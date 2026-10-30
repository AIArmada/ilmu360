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

    'guest_cookie' => [
        'name' => 'mi_dawah_guest',
        'minutes' => 60 * 24 * 30,
        'path' => '/',
        'domain' => env('SESSION_DOMAIN'),
        'secure' => env('SESSION_SECURE_COOKIE'),
        'http_only' => true,
        'same_site' => 'lax',
    ],

    'guest_credential' => [
        'ttl_days' => 30,
        'header' => 'X-Dawah-Guest-Credential',
        'input' => 'guest_credential',
    ],

    'guest_profile' => [
        'retention_days' => 90,
    ],

    'claim_cookie' => [
        'name' => 'mi_dawah_guest_claim',
        'minutes' => 60 * 24 * 30,
        'path' => '/',
        'domain' => env('SESSION_DOMAIN'),
        'secure' => env('SESSION_SECURE_COOKIE'),
        'http_only' => true,
        'same_site' => 'lax',
    ],

    'claim_capability' => [
        'ttl_days' => 30,
        'header' => 'X-Dawah-Claim-Capability',
        'input' => 'guest_claim_capability',
    ],

    'signing_key' => env('DAWAH_SHARE_SIGNING_KEY', (string) env('APP_KEY')),

    'visit_dedupe_minutes' => 5,

    'campaign_metadata_keys' => [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ],

    'reporting' => [
        'definition_version' => 'wp1',
        'reporting_start' => env('DAWAH_SHARE_REPORTING_START'),
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
