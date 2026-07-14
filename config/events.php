<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;

$appName = env('APP_NAME', 'Laravel');

return [
    'models' => [
        'event' => Event::class,
        'registration' => Registration::class,
        'attendance' => EventCheckin::class,
    ],

    'features' => [
        'auto_issue_passes' => env('EVENTS_AUTO_ISSUE_PASSES', true),
        'auto_allocate_seats' => env('EVENTS_AUTO_ALLOCATE_SEATS', true),
        'auto_revoke_passes_on_cancel' => env('EVENTS_AUTO_REVOKE_PASSES_ON_CANCEL', true),
        'owner' => [
            'enabled' => env('EVENTS_OWNER_ENABLED', true),
            'include_global' => env('EVENTS_OWNER_INCLUDE_GLOBAL', false),
            'auto_assign_on_create' => env('EVENTS_OWNER_AUTO_ASSIGN', true),
        ],
        /**
         * Historical key name from the package. These options apply to all pricing modes
         * (free, paid, mixed) — not a free-only product constraint (ADR-009 / ADR-013).
         */
        'free_only' => [
            'default_registration_mode' => env('EVENTS_DEFAULT_REGISTRATION_MODE', 'required'),
            'auto_issue_passes_for_free' => env('EVENTS_AUTO_ISSUE_PASSES_FOR_FREE', true),
            'auto_derive_pricing_from_ticket_types' => env('EVENTS_AUTO_DERIVE_PRICING', true),
            'open_door_mode' => env('EVENTS_OPEN_DOOR_MODE', 'block'),
        ],
        'commerce' => [
            /** Public paid checkout UI/API. Schema/ticket types stay available either way. */
            'public_paid_checkout_enabled' => (bool) env('EVENTS_PUBLIC_PAID_CHECKOUT_ENABLED', false),
            /** When true, mixed/paid pricing modes are accepted on write contracts. */
            'accept_paid_pricing_modes' => (bool) env('EVENTS_ACCEPT_PAID_PRICING_MODES', true),
            /** Default pricing mode for newly created events without ticket types. */
            'default_pricing_mode' => env('EVENTS_DEFAULT_PRICING_MODE', 'free'),
        ],
        'enforce_scope_capacity_on_paid_registrations' => (bool) env('EVENTS_ENFORCE_SCOPE_CAPACITY_PAID', false),
        'inventory' => [
            'default_location_id' => env('EVENTS_DEFAULT_INVENTORY_LOCATION', 'default'),
            'auto_register_quotas_on_migrate' => env('EVENTS_AUTO_REGISTER_QUOTAS', true),
        ],
    ],

    'notifications' => [
        'welcome' => [
            'enabled' => (bool) env('EVENTS_WELCOME_NOTIFICATION_ENABLED', true),
            'from_address' => env('EVENTS_WELCOME_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            'from_name' => env('EVENTS_WELCOME_FROM_NAME', env('MAIL_FROM_NAME', $appName)),
            'event_name' => env('EVENTS_WELCOME_EVENT_NAME', $appName),
            'brand_name' => env('EVENTS_WELCOME_BRAND_NAME', $appName),
        ],
        'ticket' => [
            'enabled' => (bool) env('EVENTS_TICKET_NOTIFICATION_ENABLED', true),
            'from_address' => env('EVENTS_TICKET_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            'from_name' => env('EVENTS_TICKET_FROM_NAME', env('MAIL_FROM_NAME', $appName)),
            'event_name' => env('EVENTS_TICKET_EVENT_NAME', $appName),
            'brand_name' => env('EVENTS_TICKET_BRAND_NAME', $appName),
        ],
    ],
];
