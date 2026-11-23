<?php

namespace NotificationLab;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class FirebaseProject
{
    private const MANAGEMENT = 'https://firebase.googleapis.com/v1beta1/projects/';
    private const MESSAGING = 'https://fcm.googleapis.com/v1/projects/';
    private const READ_SCOPE = 'https://www.googleapis.com/auth/firebase.readonly';
    private const WRITE_SCOPE = 'https://www.googleapis.com/auth/firebase';
    private const MESSAGING_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private ?array $serviceAccount = null;
    private array $accessTokens = [];

    public function projectId(): string
    {
        return config('notification-lab.firebase.project_id') ?: $this->account()['project_id'];
    }

    public function account(): array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }

        $path = (string) config('notification-lab.firebase.credentials');
        if (! preg_match('~^(?:[A-Za-z]:[\\\\/]|[/\\\\])~', $path)) {
            $path = base_path($path);
        }

        if (is_dir($path)) {
            $accounts = [];
            foreach (glob(rtrim($path, '/\\').'/*.json') ?: [] as $file) {
                $account = $this->readAccount($file);
                if ($account !== null) {
                    $accounts[] = $account;
                }
            }

            if (count($accounts) > 1) {
                throw new FirebaseException('Multiple service accounts found. Set NOTIFICATION_LAB_FIREBASE_CREDENTIALS to the exact JSON file.');
            }

            return $this->serviceAccount = $accounts[0]
                ?? throw new FirebaseException('No service account JSON found in the configured directory.');
        }

        return $this->serviceAccount = $this->readAccount($path)
            ?? throw new FirebaseException('The configured credentials file is missing, unreadable, or is not a service account JSON.');
    }

    private function readAccount(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $account = $raw === false ? null : json_decode($raw, true);

        if (! is_array($account) || ($account['type'] ?? '') !== 'service_account') {
            return null;
        }

        foreach (['project_id', 'client_email', 'private_key'] as $field) {
            if (! is_string($account[$field] ?? null) || trim($account[$field]) === '') {
                return null;
            }
        }

        // Google Auth uses its default OAuth endpoint with these fields.
        return array_intersect_key($account, array_flip(['type', 'project_id', 'client_email', 'private_key', 'private_key_id']));
    }

    public function webConfig(): array
    {
        $path = config('notification-lab.firebase.web_config_path');
        $raw = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        if ($raw === false) {
            throw new FirebaseException('Run php artisan notification-lab:setup to configure this browser.');
        }

        return $this->validateWebConfig(json_decode($raw, true));
    }

    public function validateWebConfig(mixed $config): array
    {
        foreach (['apiKey', 'appId', 'messagingSenderId', 'projectId'] as $key) {
            if (! is_array($config) || ! is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                throw new FirebaseException("Firebase web configuration requires {$key}.");
            }
        }

        if ($config['projectId'] !== $this->projectId()) {
            throw new FirebaseException('The web app belongs to a different Firebase project than the FCM target.');
        }

        return array_intersect_key($config, array_flip(['apiKey', 'appId', 'messagingSenderId', 'projectId']));
    }

    public function saveWebConfig(array $config): void
    {
        $path = config('notification-lab.firebase.web_config_path');
        File::ensureDirectoryExists(dirname($path));
        File::replace($path, json_encode($this->validateWebConfig($config), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
    }

    public function webApps(): array
    {
        $apps = [];
        $pageToken = null;

        do {
            $query = $pageToken ? ['pageToken' => $pageToken] : [];
            $page = $this->request('GET', self::MANAGEMENT.$this->projectId().'/webApps', self::READ_SCOPE, query: $query);
            array_push($apps, ...($page['apps'] ?? []));
            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken);

        return $apps;
    }

    public function fetchWebConfig(string $appId): array
    {
        return $this->request('GET', self::MANAGEMENT.$this->projectId().'/webApps/'.rawurlencode($appId).'/config', self::READ_SCOPE);
    }

    public function createWebApp(): string
    {
        $operation = $this->request('POST', self::MANAGEMENT.$this->projectId().'/webApps', self::WRITE_SCOPE, [
            'displayName' => 'Notification Lab',
        ]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            if ($operation['done'] ?? false) {
                return $operation['response']['appId']
                    ?? throw new FirebaseException('Firebase did not create the web app.');
            }

            $name = $operation['name'] ?? '';
            if (! preg_match('~^operations/[A-Za-z0-9:._/%-]+$~', $name)) {
                break;
            }

            sleep(1);
            $operation = $this->request('GET', 'https://firebase.googleapis.com/v1beta1/'.$name, self::WRITE_SCOPE);
        }

        throw new FirebaseException('Web app creation is still pending. Run setup again to find it.');
    }

    public function send(array $message): array
    {
        return $this->request('POST', $this->messagingUrl('/messages:send'), self::MESSAGING_SCOPE, [
            'message' => $message,
        ]);
    }

    public function topics(string $registration): array
    {
        $topics = [];
        $query = [];

        do {
            $result = $this->request('GET', $this->topicsUrl($registration), self::MESSAGING_SCOPE, query: $query);

            foreach ($result['topicSubscriptions'] ?? $result['topic_subscriptions'] ?? [] as $subscription) {
                $topic = $subscription['topicName'] ?? $subscription['topic_name'] ?? null;
                if (is_string($topic)) {
                    $topics[] = $topic;
                }
            }

            $pageToken = $result['nextPageToken'] ?? $result['next_page_token'] ?? '';
            $query = ['pageToken' => $pageToken];
        } while ($pageToken !== '');

        return $topics;
    }

    public function subscribe(string $registration, string $topic): void
    {
        $this->request('POST', $this->topicsUrl($registration), self::MESSAGING_SCOPE, (object) [], ['topic_name' => $topic], [409]);
    }

    public function unsubscribe(string $registration, string $topic): void
    {
        $this->request('DELETE', $this->topicsUrl($registration).'/'.rawurlencode($topic), self::MESSAGING_SCOPE, accepted: [404]);
    }

    private function topicsUrl(string $registration): string
    {
        return $this->messagingUrl('/registrations/'.rawurlencode($registration).'/topicSubscriptions');
    }

    private function messagingUrl(string $suffix): string
    {
        return self::MESSAGING.rawurlencode($this->projectId()).$suffix;
    }

    private function request(
        string $method,
        string $url,
        string $scope,
        array|object|null $body = null,
        array $query = [],
        array $accepted = [],
    ): array {
        try {
            $request = Http::withToken($this->accessToken($scope))
                ->acceptJson()->connectTimeout(4)->timeout(10);

            $options = ['query' => $query];
            if ($body !== null) {
                $options['json'] = $body;
            }

            $response = $request->send($method, $url, $options);
        } catch (ConnectionException) {
            throw new FirebaseException('Unable to connect to Firebase.');
        }

        if ($response->failed() && ! in_array($response->status(), $accepted, true)) {
            throw $this->firebaseError($response);
        }

        return $response->json() ?? [];
    }

    private function accessToken(string $scope): string
    {
        if (isset($this->accessTokens[$scope])) {
            return $this->accessTokens[$scope];
        }

        $account = $this->account();

        try {
            $credentials = new ServiceAccountCredentials([$scope], $account);
            $client = new \GuzzleHttp\Client();
            $token = $credentials->fetchAuthToken(static function ($request) use ($client) {
                return $client->send($request, [
                    'connect_timeout' => 4,
                    'timeout' => 10,
                ]);
            })['access_token'] ?? null;
        } catch (Throwable) {
            throw new FirebaseException('Unable to authenticate with the Firebase service account.');
        }

        return $this->accessTokens[$scope] = $token
            ?? throw new FirebaseException('Google did not return an access token for the service account.');
    }

    private function firebaseError(Response $response): FirebaseException
    {
        $details = $response->json('error.details', []);
        $codes = array_column(is_array($details) ? $details : [], 'errorCode');

        if (in_array('SENDER_ID_MISMATCH', $codes, true)) {
            return new FirebaseException('This browser was registered for a different Firebase project.', 422);
        }

        return match ($response->status()) {
            400 => new FirebaseException('Firebase rejected the notification or registration data.', 422),
            401, 403 => new FirebaseException('The Firebase service account is missing permission for this operation.', 503),
            404 => new FirebaseException('The Firebase registration or resource was not found. Try enabling notifications again.', 404),
            429 => new FirebaseException('Firebase is rate limiting requests. Try again shortly.', 429),
            default => new FirebaseException('Firebase could not complete the request.'),
        };
    }
}
