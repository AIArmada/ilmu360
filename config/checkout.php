<?php

declare(strict_types=1);
use AIArmada\Checkout\Transformers\NullSessionDataTransformer;
use AIArmada\Customers\Models\Customer;
use AIArmada\Orders\Models\Order;

/*
 * Application policy for the package-owned checkout pipeline.
 *
 * The package remains responsible for cart, pricing, promotion, voucher,
 * inventory, tax and payment orchestration. This file only fixes the
 * application defaults so a future gateway can be selected through config
 * without changing event code.
 */

return [
    'database' => [
        'table_prefix' => env('CHECKOUT_TABLE_PREFIX', env('COMMERCE_TABLE_PREFIX', '')),
        'tables' => [
            'checkout_sessions' => 'checkout_sessions',
        ],
    ],

    'defaults' => [
        'currency' => env('CHECKOUT_CURRENCY', 'MYR'),
        'session_ttl' => (int) env('CHECKOUT_SESSION_TTL', 900),
        'session_query_param' => 'session',
        'shipping_rate' => (int) env('CHECKOUT_DEFAULT_SHIPPING_RATE', 0),
    ],

    'models' => [
        'customer' => Customer::class,
        'order' => Order::class,
    ],

    'transformers' => [
        'billing' => NullSessionDataTransformer::class,
        'shipping' => NullSessionDataTransformer::class,
    ],

    'steps' => [
        'enabled' => [
            'validate_cart' => true,
            'resolve_customer' => true,
            'calculate_pricing' => true,
            'apply_discounts' => true,
            'calculate_shipping' => true,
            'calculate_tax' => true,
            'reserve_inventory' => true,
            'process_payment' => true,
            'persist_customer' => true,
            'create_order' => true,
            'create_event_registrations' => true,
            'issue_event_passes' => true,
            'dispatch_documents' => true,
        ],
        'order' => [
            'validate_cart',
            'resolve_customer',
            'calculate_pricing',
            'apply_discounts',
            'calculate_shipping',
            'calculate_tax',
            'reserve_inventory',
            'process_payment',
            'persist_customer',
            'create_order',
            'create_event_registrations',
            'issue_event_passes',
            'dispatch_documents',
        ],
    ],

    'integrations' => [
        'inventory' => [
            'enabled' => true,
            'validate_stock' => true,
            'reserve_before_payment' => true,
            'release_on_failure' => true,
            'reservation_ttl' => (int) env('CHECKOUT_INVENTORY_RESERVATION_TTL', 900),
        ],
        // Events do not ship anything in v1. The package step stays installed
        // so another product can enable shipping independently later.
        'shipping' => [
            'enabled' => false,
            'require_selection' => false,
            'jnt' => [
                'enabled' => false,
                'auto_detect' => false,
            ],
        ],
        // Tax is deliberately dormant in v1, but the package step and its
        // configuration seam remain available for a future activation.
        'tax' => [
            'enabled' => false,
        ],
        'promotions' => [
            'enabled' => true,
            'auto_apply' => true,
        ],
        'vouchers' => [
            'enabled' => true,
            'allow_multiple' => false,
        ],
        'chip' => [
            'enabled' => true,
        ],
    ],

    'payment' => [
        // This is a platform setting, never a buyer choice. It can be changed
        // to another registered processor without changing event checkout.
        'default_gateway' => env('CHECKOUT_DEFAULT_GATEWAY', 'cashier-chip'),
        'gateway_priority' => ['cashier-chip', 'chip', 'cashier'],
        'prefer_actor' => true,
        'retry_limit' => 3,
        // Optional provider packages register their processors through the
        // checkout.payment_processors container tag.
        'gateways' => [
            'cashier' => ['enabled' => true],
            'cashier-chip' => ['enabled' => true],
            'chip' => ['enabled' => true],
        ],
    ],

    'create_order' => [
        'confirm_payment' => true,
    ],

    'owner' => [
        'enabled' => env('CHECKOUT_OWNER_ENABLED', false),
        'include_global' => false,
        'auto_assign_on_create' => true,
    ],

    'routes' => [
        'enabled' => env('CHECKOUT_ROUTES_ENABLED', true),
        'prefix' => env('CHECKOUT_ROUTE_PREFIX', 'checkout'),
        'middleware' => ['web'],
        'callbacks' => [
            'success' => 'payment/success',
            'failure' => 'payment/failure',
            'cancel' => 'payment/cancel',
        ],
        'webhook_prefix' => env('CHECKOUT_WEBHOOK_PREFIX', 'webhooks'),
        'webhook_path' => 'checkout',
        'webhook_middleware' => ['api'],
    ],

    'redirects' => [
        'success' => '/checkout/result/{session_id}',
        'failure' => '/checkout/result/{session_id}?status=failure',
        'cancel' => '/checkout/result/{session_id}?status=cancelled',
    ],

    'response_mode' => 'redirect',

    'views' => [
        'enabled' => true,
        'layout' => 'layouts.app',
        'routes' => [
            'success' => 'checkout::success',
            'failure' => 'checkout::failure',
            'cancel' => 'checkout::cancel',
        ],
    ],

    'webhooks' => [
        'verify_signature' => env('CHECKOUT_WEBHOOK_VERIFY_SIGNATURE', true),
        'log_channel' => env('CHECKOUT_WEBHOOK_LOG_CHANNEL'),
    ],

    'documents' => [
        'queue' => 'default',
        'generate_invoice' => false,
        'generate_receipt' => false,
    ],
];
