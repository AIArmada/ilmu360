<?php

declare(strict_types=1);

return [
    'defaults' => [
        'digest_delivery_time' => '08:00:00',
        'digest_weekly_day' => 1,
        'fallback_strategy' => 'next_available',
        'preferred_channels' => ['in_app', 'push', 'email', 'whatsapp'],
        'fallback_channels' => ['in_app', 'email'],
    ],

    'push' => [
        'provider' => env('NOTIFICATION_PUSH_PROVIDER', 'fcm'),
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'),
        'endpoint' => env('FCM_ENDPOINT', 'https://fcm.googleapis.com/v1/projects/{project}/messages:send'),
    ],

    'whatsapp' => [
        'provider' => env('NOTIFICATION_WHATSAPP_PROVIDER', 'meta_cloud'),
        'base_url' => env('WHATSAPP_CLOUD_API_BASE_URL', 'https://graph.facebook.com'),
        'version' => env('WHATSAPP_CLOUD_API_VERSION', 'v23.0'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_CLOUD_ACCESS_TOKEN'),
    ],
];
