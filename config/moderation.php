<?php

declare(strict_types=1);

$tablePrefix = env('MODERATION_TABLE_PREFIX', 'moderation_');

return [

    /* Database */
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => env('MODERATION_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'jsonb')),
        'tables' => [
            'blocks' => env('MODERATION_TABLE_BLOCKS', $tablePrefix.'blocks'),
            'moderation_actions' => env('MODERATION_TABLE_ACTIONS', $tablePrefix.'actions'),
        ],
    ],

    /* Features / Behavior */
    'features' => [
        'owner' => [
            'enabled' => env('MODERATION_OWNER_ENABLED', true),
        ],
    ],

    /* Defaults */
    'defaults' => [
        'block_duration_days' => (int) env('MODERATION_BLOCK_DURATION_DAYS', 30),
    ],

];
