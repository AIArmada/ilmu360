<?php

declare(strict_types=1);

return [
    'defaults' => [
        'digest_delivery_time' => '08:00:00',
        'digest_weekly_day' => 1,
    ],

    'push' => [
        'provider' => env('NOTIFICATION_PUSH_PROVIDER', 'fcm'),
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'),
        'endpoint' => env('FCM_ENDPOINT', 'https://fcm.googleapis.com/v1/projects/{project}/messages:send'),
    ],
];
