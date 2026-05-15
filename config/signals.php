<?php

declare(strict_types=1);

$tablePrefix = 'signal_';

return [
    /* Database */
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => env('SIGNALS_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'json')),
        'tables' => [
            'tracked_properties' => $tablePrefix.'tracked_properties',
            'identities' => $tablePrefix.'identities',
            'sessions' => $tablePrefix.'sessions',
            'events' => $tablePrefix.'events',
            'daily_metrics' => $tablePrefix.'daily_metrics',
            'goals' => $tablePrefix.'goals',
            'segments' => $tablePrefix.'segments',
            'saved_reports' => $tablePrefix.'saved_reports',
            'alert_rules' => $tablePrefix.'alert_rules',
            'alert_logs' => $tablePrefix.'alert_logs',
        ],
    ],

    /* Defaults */
    'defaults' => [
        'currency' => 'MYR',
        'timezone' => 'UTC',
        'property_type' => 'website',
        'page_view_event_name' => 'page_view',
        'primary_outcome_event_name' => env('SIGNALS_PRIMARY_OUTCOME_EVENT_NAME', 'conversion.completed'),
        'starter_funnel' => [
            [
                'label' => 'Visited',
                'event_name' => 'page_view',
                'event_category' => 'page_view',
            ],
            [
                'label' => 'Explored Further',
                'event_name' => 'page_view',
                'event_category' => 'page_view',
            ],
            [
                'label' => 'Completed Outcome',
                'event_name' => null,
                'event_category' => null,
            ],
        ],
        'session_duration_seconds' => 1800,
    ],

    /* Features / Behavior */
    'features' => [
        'owner' => [
            'enabled' => true,
            'include_global' => false,
            'auto_assign_on_create' => true,
        ],
        'ua_parsing' => [
            'enabled' => true,
            'store_raw' => true, // store the raw User-Agent string on signal_sessions
        ],
        'ip_tracking' => [
            'enabled' => true,
            'anonymize' => false, // true = zero-out last octet (IPv4) / last 80 bits (IPv6)
        ],
        'auth_tracking' => [
            'enabled' => false, // opt-in: when true, links auth()->user() to SignalIdentity
        ],
        'geolocation' => [
            'enabled' => true,  // allow browser geolocation coordinate capture via /collect/geo
            'reverse_geocode' => [
                'enabled' => false,  // opt-in: reverse-geocode coordinates to address fields
                'async' => true,     // dispatch ReverseGeocodeSessionJob instead of inline
                'store_raw_payload' => false, // persist raw provider response in raw_reverse_geocode_payload
            ],
        ],
        'monetary' => [
            'enabled' => false,  // false = hide all monetary/revenue UI (stat cards, columns, goal types, alert metrics, condition fields)
        ],
    ],

    /* Integrations */
    'integrations' => [
        'cart' => [
            'enabled' => true,
            'listen_for_item_added' => true,
            'listen_for_item_removed' => true,
            'listen_for_cleared' => true,
            'item_added_event_name' => 'cart.item.added',
            'item_removed_event_name' => 'cart.item.removed',
            'cleared_event_name' => 'cart.cleared',
            'event_category' => 'cart',
        ],
        'checkout' => [
            'enabled' => true,
            'listen_for_started' => true,
            'listen_for_completed' => true,
            'started_event_name' => 'checkout.started',
            'event_name' => 'checkout.completed',
            'event_category' => 'checkout',
        ],
        'orders' => [
            'enabled' => true,
            'listen_for_paid' => true,
            'event_name' => 'order.paid',
            'event_category' => 'conversion',
        ],
        'vouchers' => [
            'enabled' => true,
            'listen_for_applied' => true,
            'listen_for_removed' => true,
            'applied_event_name' => 'voucher.applied',
            'removed_event_name' => 'voucher.removed',
            'event_category' => 'promotion',
        ],
        'affiliates' => [
            'enabled' => true,
            'listen_for_attributed' => true,
            'listen_for_conversion_recorded' => true,
            'attributed_event_name' => 'affiliate.attributed',
            'attributed_event_category' => 'acquisition',
            'conversion_event_name' => 'affiliate.conversion.recorded',
            'conversion_event_category' => 'conversion',
        ],
    ],

    /* HTTP */
    'http' => [
        'prefix' => 'api/signals',
        'middleware' => ['api'],
        'tracker_script' => 'tracker.js',
    ],
];
