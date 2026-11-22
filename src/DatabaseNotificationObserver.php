<?php

namespace NotificationLab;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class DatabaseNotificationObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Model $notification): void
    {
        if (DatabaseChanged::clientSettings() === null) {
            return;
        }

        try {
            event(new DatabaseChanged());
        } catch (Throwable $error) {
            Log::warning('Notification Lab could not broadcast a database update.', [
                'exception' => $error::class,
            ]);
        }
    }
}
