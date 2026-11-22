<?php

namespace NotificationLab;

use Illuminate\Console\Command;

class SetupCommand extends Command
{
    protected $signature = 'notification-lab:setup
        {--app-id= : Firebase web app ID to use}
        {--web-config= : JSON file exported from Firebase Console}
        {--create-web-app : Create a web app if the project has none}';

    protected $description = 'Publish Notification Lab configuration and connect a Firebase web app';

    public function handle(FirebaseProject $firebase): int
    {
        if (! is_file(config_path('notification-lab.php'))) {
            $this->call('vendor:publish', ['--tag' => 'notification-lab-config']);
        }

        try {
            $firebase->account();
            $this->info('Firebase target project: '.$firebase->projectId());
            $webConfig = $this->option('web-config');
            $appId = $this->option('app-id');

            if ($webConfig) {
                $raw = is_readable($webConfig) ? @file_get_contents($webConfig) : false;
                if ($raw === false) {
                    throw new FirebaseException('The supplied web config JSON file is unreadable.');
                }

                $firebase->saveWebConfig($firebase->validateWebConfig(json_decode($raw, true)));
            } elseif ($appId) {
                $firebase->saveWebConfig($firebase->fetchWebConfig($appId));
            } elseif (! is_file(config('notification-lab.firebase.web_config_path'))) {
                $firebase->saveWebConfig($this->discover($firebase));
            }

            $firebase->webConfig();
        } catch (FirebaseException $error) {
            $this->error($error->getMessage());
            $this->line('To supply a web configuration manually, rerun with --web-config=/path/to/firebase-web.json.');

            return self::FAILURE;
        }

        $this->info('Firebase browser configuration is ready.');
        $this->line('Enable NOTIFICATION_LAB_ENABLED and authorize the configured gate in your application.');

        return self::SUCCESS;
    }

    private function discover(FirebaseProject $firebase): array
    {
        $apps = $firebase->webApps();

        if ($apps === []) {
            $create = $this->option('create-web-app') || ($this->input->isInteractive()
                && $this->confirm('Create a Firebase Web App named Notification Lab?'));

            if (! $create) {
                throw new FirebaseException('This Firebase project has no web app.');
            }

            return $firebase->fetchWebConfig($firebase->createWebApp());
        }

        if (count($apps) === 1) {
            return $firebase->fetchWebConfig($apps[0]['appId']);
        }

        $choices = array_column($apps, 'appId');
        foreach ($apps as $app) {
            $this->line(($app['displayName'] ?? 'Web app').' — '.$app['appId']);
        }

        if (! $this->input->isInteractive()) {
            throw new FirebaseException('Multiple web apps found. Rerun setup with --app-id.');
        }

        return $firebase->fetchWebConfig($this->choice('Choose a Firebase Web App by ID', $choices));
    }
}
