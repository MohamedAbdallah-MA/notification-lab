<?php

namespace NotificationLab;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Validation\Rule;

class LabController
{
    public function __construct(private FirebaseProject $firebase) {}

    public function index()
    {
        $firebase = null;
        $error = null;

        try {
            $firebase = $this->firebase->webConfig();
        } catch (FirebaseException $exception) {
            $error = $exception->getMessage();
        }

        $settings = [
            'firebase' => $firebase,
            'firebaseError' => $error,
            'vapidKey' => config('notification-lab.firebase.vapid_key'),
            'mode' => $this->mode(),
            'testTopics' => config('notification-lab.firebase.test_topics'),
            'broadcast' => DatabaseChanged::clientSettings(),
            'routes' => [
                'browser' => route('notification-lab.browser', absolute: false),
                'topics' => route('notification-lab.topics', absolute: false),
                'send' => route('notification-lab.send', absolute: false),
                'worker' => route('notification-lab.worker', absolute: false),
                'database' => route('notification-lab.database', absolute: false),
                'broadcast' => route('notification-lab.broadcast', absolute: false),
            ],
        ];

        return response()->view('notification-lab::dashboard', ['settings' => $settings])
            ->header('Cache-Control', 'no-store');
    }

    public function worker(): Response
    {
        try {
            $config = json_encode($this->firebase->webConfig(), JSON_THROW_ON_ERROR | JSON_HEX_TAG);
        } catch (FirebaseException $error) {
            abort(503, $error->getMessage());
        }

        return response('self.notificationLabFirebase = '.$config.';'.PHP_EOL.file_get_contents(__DIR__.'/../dist/js/worker.js'))
            ->header('Content-Type', 'text/javascript; charset=UTF-8')
            ->header('Cache-Control', 'no-cache');
    }

    public function asset(string $asset)
    {
        $folder = $asset === 'lab.css' ? 'css' : 'js';

        return response()->file(__DIR__.'/../dist/'.$folder.'/'.$asset, [
            'Content-Type' => $asset === 'lab.css' ? 'text/css; charset=UTF-8' : 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'public, no-cache',
        ]);
    }

    public function browser(Request $request): JsonResponse
    {
        return $this->firebaseAction(function () use ($request): array {
            $this->firebase->webConfig();
            $data = $request->validate(['registration' => ['required', 'string', 'max:4096']]);

            $request->session()->put('notification-lab.browser', [
                'project' => $this->firebase->projectId(),
                'mode' => $this->mode(),
                'registration' => $data['registration'],
            ]);

            return ['registered' => true];
        });
    }

    public function topics(Request $request): JsonResponse
    {
        return $this->firebaseAction(fn () => ['topics' => $this->firebase->topics($this->registration($request))]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        return $this->changeTopic($request, true);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        return $this->changeTopic($request, false);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', Rule::in(['browser', 'topic'])],
            'topic' => ['required_if:target,topic', 'nullable', Rule::in(config('notification-lab.firebase.test_topics'))],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:1024'],
            'data' => ['nullable', 'array'],
            'test_id' => ['required', 'uuid'],
        ]);

        $payload = $data['data'] ?? [];
        foreach ($payload as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_string($value)
                || in_array($key, ['from', 'message_type', 'collapse_key'], true)
                || str_starts_with($key, 'google.') || str_starts_with($key, 'gcm.')
                || str_starts_with($key, 'notification_lab_')) {
                return response()->json(['message' => 'Use a JSON object with string values and non-reserved keys.'], 422);
            }
        }

        return $this->firebaseAction(function () use ($request, $data, $payload): array {
            $registration = $this->registration($request);
            $destination = $data['target'] === 'topic'
                ? ['topic' => $data['topic']]
                : [$this->mode() => $registration];

            if ($data['target'] === 'topic' && ! in_array($data['topic'], $this->firebase->topics($registration), true)) {
                throw new FirebaseException('Subscribe this browser to the test topic first.', 422);
            }

            $message = $destination + [
                'notification' => ['title' => $data['title'], 'body' => $data['body'] ?? ''],
                'data' => $payload + [
                    'notification_lab_test_id' => $data['test_id'],
                    'notification_lab_target_type' => $data['target'] === 'topic' ? 'topic' : 'direct',
                    'notification_lab_target' => $data['target'] === 'topic' ? $data['topic'] : 'browser',
                ],
            ];

            $accepted = $this->firebase->send($message);

            return ['accepted' => true, 'messageId' => $accepted['name'] ?? null];
        });
    }

    public function database(Request $request, DatabaseNotifications $notifications): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['read', 'unread'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        try {
            return response()->json($notifications->page($filters))->header('Cache-Control', 'no-store');
        } catch (\Illuminate\Database\QueryException) {
            return response()->json(['message' => 'Unable to read the application notification table.'], 503);
        }
    }

    public function broadcastAuth(Request $request)
    {
        abort_unless(DatabaseChanged::clientSettings() !== null, 404);
        abort_unless($request->input('channel_name') === 'private-'.config('notification-lab.database.channel'), 403);

        return Broadcast::connection(DatabaseChanged::connectionName())->auth($request);
    }

    private function changeTopic(Request $request, bool $subscribe): JsonResponse
    {
        $data = $request->validate(['topic' => ['required', 'regex:/\A[A-Za-z0-9_.~%-]{1,900}\z/']]);

        return $this->firebaseAction(function () use ($request, $data, $subscribe): array {
            $registration = $this->registration($request);

            if ($subscribe) {
                $this->firebase->subscribe($registration, $data['topic']);
            } else {
                $this->firebase->unsubscribe($registration, $data['topic']);
            }

            return ['topics' => $this->firebase->topics($registration)];
        });
    }

    private function registration(Request $request): string
    {
        $browser = $request->session()->get('notification-lab.browser');

        if (($browser['project'] ?? null) !== $this->firebase->projectId() || $browser['mode'] !== $this->mode()) {
            throw new FirebaseException('Enable notifications on this browser first.', 409);
        }

        return $browser['registration'];
    }

    private function mode(): string
    {
        return config('notification-lab.firebase.registration') === 'fid' ? 'fid' : 'token';
    }

    private function firebaseAction(callable $action): JsonResponse
    {
        try {
            return response()->json($action())->header('Cache-Control', 'no-store');
        } catch (FirebaseException $error) {
            return response()->json(['message' => $error->getMessage()], $error->status);
        }
    }
}
