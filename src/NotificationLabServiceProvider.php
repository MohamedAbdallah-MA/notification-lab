<?php

namespace NotificationLab;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class NotificationLabServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/notification-lab.php', 'notification-lab');
        $this->app->scoped(FirebaseProject::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/notification-lab.php' => config_path('notification-lab.php'),
        ], 'notification-lab-config');

        if ($this->app->runningInConsole()) {
            $this->commands([SetupCommand::class]);
        }

        if (! config('notification-lab.enabled')) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'notification-lab');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if (DatabaseChanged::clientSettings() !== null) {
            Broadcast::connection(DatabaseChanged::connectionName())->channel(config('notification-lab.database.channel'), function ($user): bool {
                return app(Gate::class)->forUser($user)->allows(config('notification-lab.gate'));
            });

            $model = config('notification-lab.database.model');
            $model::observe(DatabaseNotificationObserver::class);
        }
    }
}
