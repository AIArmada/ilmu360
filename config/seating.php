<?php

declare(strict_types=1);

return [
    'database' => [
        'tables' => [
            'seat_maps' => env('SEATING_TABLE_SEAT_MAPS', 'seat_maps'),
            'seat_sections' => env('SEATING_TABLE_SEAT_SECTIONS', 'seat_sections'),
            'seats' => env('SEATING_TABLE_SEATS', 'seats'),
            'seat_holds' => env('SEATING_TABLE_SEAT_HOLDS', 'seat_holds'),
            'seat_allocations' => env('SEATING_TABLE_SEAT_ALLOCATIONS', 'seat_allocations'),
        ],
        'json_column_type' => env('SEATING_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'json')),
    ],

    'holds' => [
        'ttl_minutes' => (int) env('SEATING_HOLD_TTL_MINUTES', 15),
    ],

    'owner' => [
        'enabled' => env('SEATING_OWNER_ENABLED', true),
        'include_global' => env('SEATING_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('SEATING_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],

    'modes' => [
        // none — standing, open entry, no row created
        // general_admission — section, no specific seat
        // assigned — specific seat
        // hybrid — section + optional seat
    ],

    'scheduling' => [
        'release_expired_holds' => env('SEATING_RELEASE_EXPIRED_HOLDS', true),
    ],
];
