<?php

declare(strict_types=1);

return [
    'database' => [
        'table_prefix' => env('TICKETING_TABLE_PREFIX', 'ticket_'),
        'tables' => [
            'ticket_types' => env('TICKETING_TICKET_TYPES_TABLE', 'ticket_ticket_types'),
            'ticket_type_components' => env('TICKETING_TICKET_TYPE_COMPONENTS_TABLE', 'ticket_ticket_type_components'),
            'ticket_type_products' => env('TICKETING_TICKET_TYPE_PRODUCTS_TABLE', 'ticket_ticket_type_products'),
            'ticket_type_seating_options' => env('TICKETING_TICKET_TYPE_SEATING_OPTIONS_TABLE', 'ticket_ticket_type_seating_options'),
            'passes' => env('TICKETING_PASSES_TABLE', 'ticket_passes'),
            'pass_holders' => env('TICKETING_PASS_HOLDERS_TABLE', 'ticket_pass_holders'),
            'pass_transfers' => env('TICKETING_PASS_TRANSFERS_TABLE', 'ticket_pass_transfers'),
        ],
    ],
    'defaults' => [
        'currency' => env('TICKETING_CURRENCY', env('COMMERCE_CURRENCY', 'MYR')),
        'pass_no_prefix' => env('TICKETING_PASS_NO_PREFIX', 'PASS-'),
    ],
    'transfers' => [
        'bulk_max_size' => env('TICKETING_BULK_TRANSFER_MAX', 100),
        'expiry_grace_period' => env('TICKETING_TRANSFER_EXPIRY_GRACE', 0),
    ],
    'notifications' => [
        'ticket' => [
            'enabled' => true,
            'from_address' => env('TICKETING_FROM_ADDRESS'),
            'from_name' => env('TICKETING_FROM_NAME'),
        ],
    ],
    'features' => [
        'auto_issue_passes' => env('TICKETING_AUTO_ISSUE_PASSES', true),
    ],
    'events' => [
        'pricing_consistency_check' => env('TICKETING_PRICING_CONSISTENCY_CHECK', true),
    ],
];
