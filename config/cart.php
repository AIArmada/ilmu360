<?php

declare(strict_types=1);

return [
    'database' => [
        'table' => env('CART_DB_TABLE', 'carts'),
        'conditions_table' => env('CART_CONDITIONS_TABLE', 'conditions'),
        'ttl' => (int) env('CART_DB_TTL', 60 * 60 * 24 * 30),
        'lock_for_update' => (bool) env('CART_DB_LOCK_FOR_UPDATE', true),
    ],

    'money' => [
        'default_currency' => env('CART_DEFAULT_CURRENCY', 'MYR'),
        'rounding_mode' => env('CART_ROUNDING_MODE', 'half_up'),
    ],

    'empty_cart_behavior' => env('CART_EMPTY_BEHAVIOR', 'destroy'),

    'events' => (bool) env('CART_EVENTS_ENABLED', true),

    'owner' => [
        'enabled' => env('CART_OWNER_ENABLED', false),
        'include_global' => env('CART_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('CART_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],

    'limits' => [
        'max_items' => (int) env('CART_MAX_ITEMS', 1000),
        'max_item_quantity' => (int) env('CART_MAX_QUANTITY', 10000),
        'max_data_size_bytes' => (int) env('CART_MAX_DATA_BYTES', 1048576),
        'max_string_length' => (int) env('CART_MAX_STRING_LENGTH', 255),
    ],

    'performance' => [
        'lazy_pipeline' => (bool) env('CART_LAZY_PIPELINE_ENABLED', true),
    ],

    'migration' => [
        'auto_migrate_on_login' => (bool) env('CART_AUTO_MIGRATE', true),
        'merge_strategy' => env('CART_MERGE_STRATEGY', 'add_quantities'),
    ],
];
