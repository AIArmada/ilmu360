<?php

declare(strict_types=1);

return [
    // Add future Filament or non-public panel IDs here using the panel ID as the key.
    'panels' => [
        'public' => [
            'enabled' => env('PRODUCT_SIGNALS_PUBLIC_ENABLED', true),
            'slug' => env('PRODUCT_SIGNALS_PUBLIC_PROPERTY_SLUG'),
        ],
        'admin' => [
            'enabled' => env('PRODUCT_SIGNALS_ADMIN_ENABLED', true),
            'slug' => env('PRODUCT_SIGNALS_ADMIN_PROPERTY_SLUG'),
        ],
        'ahli' => [
            'enabled' => env('PRODUCT_SIGNALS_AHLI_ENABLED', true),
            'slug' => env('PRODUCT_SIGNALS_AHLI_PROPERTY_SLUG'),
        ],
    ],

    'identity' => [
        'anonymous_cookie' => env('PRODUCT_SIGNALS_ANONYMOUS_COOKIE', 'mi_signals_anonymous_id'),
        'session_cookie' => env('PRODUCT_SIGNALS_SESSION_COOKIE', 'mi_signals_session_id'),
    ],
];
