<?php

return [
    'enabled' => env('NOTIFICATION_LAB_ENABLED', false),
    'path' => 'notification-lab',
    'gate' => 'viewNotificationLab',

    'firebase' => [
        'credentials' => env('NOTIFICATION_LAB_FIREBASE_CREDENTIALS', storage_path('firebase')),
        'project_id' => env('NOTIFICATION_LAB_FIREBASE_PROJECT_ID'),
        'web_config_path' => storage_path('app/private/notification-lab-web.json'),
        'vapid_key' => env('NOTIFICATION_LAB_VAPID_KEY'),
        'registration' => env('NOTIFICATION_LAB_REGISTRATION', 'token'),
        'test_topics' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOTIFICATION_LAB_TEST_TOPICS', ''))
        ))),
    ],

    'database' => [
        'model' => Illuminate\Notifications\DatabaseNotification::class,
        'broadcast' => env('NOTIFICATION_LAB_BROADCAST', true),
        'broadcast_connection' => env('NOTIFICATION_LAB_BROADCAST_CONNECTION'),
        'channel' => 'notification-lab.database',
    ],
];
