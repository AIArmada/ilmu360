<?php

declare(strict_types=1);

return [
    'query_parameter' => 'share',

    'provider_query_parameter' => 'channel',

    'ttl_days' => 30,

    'cookie' => [
        'name' => 'mi_dawah_share',
        'minutes' => 60 * 24 * 30,
        'path' => '/',
        'domain' => env('SESSION_DOMAIN'),
        'secure' => env('SESSION_SECURE_COOKIE'),
        'http_only' => true,
        'same_site' => 'lax',
    ],

    'signing_key' => env('DAWAH_SHARE_SIGNING_KEY', (string) env('APP_KEY')),

    'visit_dedupe_minutes' => 5,

    'runtime_data_purge' => [
        'enabled' => env('DAWAH_SHARE_PURGE_AFFILIATE_RUNTIME_DATA', false),
    ],

    'bot_user_agents' => [
        'bot',
        'crawler',
        'spider',
        'facebookexternalhit',
        'slackbot',
        'whatsapp',
        'telegrambot',
        'twitterbot',
        'linkedinbot',
        'discordbot',
    ],
];
