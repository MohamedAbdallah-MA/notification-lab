<?php

namespace NotificationLab;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;

class DatabaseChanged implements ShouldBroadcastNow
{
    public static function connectionName(): string
    {
        return config('notification-lab.database.broadcast_connection') ?: (string) config('broadcasting.default');
    }

    public static function clientSettings(): ?array
    {
        if (! config('notification-lab.database.broadcast')) {
            return null;
        }

        $connection = config('broadcasting.connections.'.self::connectionName(), []);
        $driver = $connection['driver'] ?? null;
        $key = $connection['key'] ?? null;

        if (! in_array($driver, ['reverb', 'pusher'], true) || ! is_string($key) || $key === '') {
            return null;
        }

        $options = $connection['options'] ?? [];

        return [
            'key' => $key,
            'driver' => $driver,
            'channel' => config('notification-lab.database.channel'),
            'host' => $options['host'] ?? null,
            'port' => $options['port'] ?? null,
            'tls' => is_null($options['scheme'] ?? null) || $options['scheme'] === 'https',
            'cluster' => $options['cluster'] ?? 'mt1',
        ];
    }

    public function broadcastConnections(): array
    {
        return [self::connectionName()];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(config('notification-lab.database.channel'));
    }

    public function broadcastAs(): string
    {
        return 'notification-lab.database.changed';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
