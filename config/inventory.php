<?php

declare(strict_types=1);

$tablePrefix = env('INVENTORY_TABLE_PREFIX', 'inventory_');

$tables = [
    'locations' => env('INVENTORY_LOCATIONS_TABLE', $tablePrefix.'locations'),
    'levels' => env('INVENTORY_LEVELS_TABLE', $tablePrefix.'levels'),
    'movements' => env('INVENTORY_MOVEMENTS_TABLE', $tablePrefix.'movements'),
    'allocations' => env('INVENTORY_ALLOCATIONS_TABLE', $tablePrefix.'allocations'),
    'batches' => env('INVENTORY_BATCHES_TABLE', $tablePrefix.'batches'),
    'serials' => env('INVENTORY_SERIALS_TABLE', $tablePrefix.'serials'),
    'serial_history' => env('INVENTORY_SERIAL_HISTORY_TABLE', $tablePrefix.'serial_history'),
    'cost_layers' => env('INVENTORY_COST_LAYERS_TABLE', $tablePrefix.'cost_layers'),
    'standard_costs' => env('INVENTORY_STANDARD_COSTS_TABLE', $tablePrefix.'standard_costs'),
    'valuation_snapshots' => env('INVENTORY_VALUATION_SNAPSHOTS_TABLE', $tablePrefix.'valuation_snapshots'),
    'backorders' => env('INVENTORY_BACKORDERS_TABLE', $tablePrefix.'backorders'),
    'demand_history' => env('INVENTORY_DEMAND_HISTORY_TABLE', $tablePrefix.'demand_history'),
    'supplier_leadtimes' => env('INVENTORY_SUPPLIER_LEADTIMES_TABLE', $tablePrefix.'supplier_leadtimes'),
    'reorder_suggestions' => env('INVENTORY_REORDER_SUGGESTIONS_TABLE', $tablePrefix.'reorder_suggestions'),
];

return [
    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => env('INVENTORY_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'jsonb')),
        'tables' => $tables,
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'currency' => env('INVENTORY_CURRENCY', env('COMMERCE_CURRENCY', 'MYR')),
    ],

    'default_reorder_point' => env('INVENTORY_DEFAULT_REORDER_POINT', 10),
    'allocation_strategy' => env('INVENTORY_ALLOCATION_STRATEGY', 'priority'), // priority, fifo, least_stock, single_location
    'allocation_ttl_minutes' => env('INVENTORY_ALLOCATION_TTL', 30),
    'allow_split_allocation' => env('INVENTORY_ALLOW_SPLIT', true),

    /*
    |--------------------------------------------------------------------------
    | Ownership (Multi-Tenancy)
    |--------------------------------------------------------------------------
    |
    | Register a resolver that returns the current owner (merchant, tenant, etc).
        | When enabled, inventory is automatically scoped to the current owner.
        | The OwnerResolverInterface binding is provided by commerce-support.
    |
    */
    'owner' => [
        'enabled' => env('INVENTORY_OWNER_ENABLED', false),
        'include_global' => env('INVENTORY_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('INVENTORY_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cart Integration
    |--------------------------------------------------------------------------
    |
    | Configure tight integration with the Cart package when installed.
    |
    */
    'cart' => [
        // Enable cart integration
        'enabled' => env('INVENTORY_CART_ENABLED', true),

        // Validate stock availability when adding items to cart
        'validate_on_add' => env('INVENTORY_VALIDATE_ON_ADD', false),

        // Auto-allocate inventory when items are added to cart
        'auto_allocate_on_add' => env('INVENTORY_AUTO_ALLOCATE_ON_ADD', false),

        // Reserve stock when checkout starts
        'reserve_on_checkout' => env('INVENTORY_RESERVE_ON_CHECKOUT', true),

        // Block checkout if any item cannot be reserved
        'block_checkout_on_insufficient' => env('INVENTORY_BLOCK_CHECKOUT_ON_INSUFFICIENT', true),

        // Default allocation TTL in minutes
        'allocation_ttl_minutes' => env('INVENTORY_ALLOCATION_TTL', 30),

        // Reservation TTL during checkout flow
        'checkout_reservation_ttl_minutes' => env(
            'INVENTORY_CHECKOUT_RESERVATION_TTL',
            env('INVENTORY_ALLOCATION_TTL', 30)
        ),

        // Allow adding items even when out of stock (backorder support)
        'allow_backorder' => env('INVENTORY_ALLOW_BACKORDER', false),

        // Maximum backorder quantity per item (null = unlimited)
        'max_backorder_quantity' => env('INVENTORY_MAX_BACKORDER_QTY'),

        // Metadata key for storing allocation status on cart items
        'allocation_metadata_key' => 'inventory_allocated',

        // Metadata key for storing backorder status on cart items
        'backorder_metadata_key' => 'is_backorder',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Integration
    |--------------------------------------------------------------------------
    */
    'payment' => [
        'auto_commit' => env('INVENTORY_AUTO_COMMIT', true),
        'events' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Orders Integration
    |--------------------------------------------------------------------------
    |
    | Configure integration with the Orders package when installed.
    | Listens for InventoryDeductionRequired and InventoryReleaseRequired events.
    |
    */
    'orders' => [
        'enabled' => env('INVENTORY_ORDERS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */
    'events' => [
        'low_inventory' => env('INVENTORY_EVENT_LOW', true),
        'out_of_inventory' => env('INVENTORY_EVENT_OUT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    */
    'cleanup' => [
        'keep_expired_for_minutes' => env('INVENTORY_KEEP_EXPIRED', 0),
    ],
];
