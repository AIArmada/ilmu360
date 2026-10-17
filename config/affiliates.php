<?php

declare(strict_types=1);

$tablePrefix = env('AFFILIATES_TABLE_PREFIX', 'affiliate_');
$tables = [
    'affiliates' => $tablePrefix.'affiliates',
    'attributions' => $tablePrefix.'attributions',
    'conversions' => $tablePrefix.'conversions',
    'payouts' => $tablePrefix.'payouts',
    'payout_events' => $tablePrefix.'payout_events',
    'support_tickets' => $tablePrefix.'support_tickets',
    'support_messages' => $tablePrefix.'support_messages',
    'tax_documents' => $tablePrefix.'tax_documents',
    'touchpoints' => $tablePrefix.'touchpoints',
    'ranks' => $tablePrefix.'ranks',
    'upline' => $tablePrefix.'upline',
    'rank_histories' => $tablePrefix.'rank_histories',
    'daily_stats' => $tablePrefix.'daily_stats',
    'fraud_signals' => $tablePrefix.'fraud_signals',
    'balances' => $tablePrefix.'balances',
    'payout_methods' => $tablePrefix.'payout_methods',
    'payout_holds' => $tablePrefix.'payout_holds',
    'programs' => $tablePrefix.'programs',
    'program_tiers' => $tablePrefix.'program_tiers',
    'program_memberships' => $tablePrefix.'program_memberships',
    'program_creatives' => $tablePrefix.'program_creatives',
    'links' => $tablePrefix.'links',
    'commission_rules' => $tablePrefix.'commission_rules',
    'volume_tiers' => $tablePrefix.'volume_tiers',
    'commission_promotions' => $tablePrefix.'commission_promotions',
    'commission_templates' => $tablePrefix.'commission_templates',
];

return [
    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */
    'database' => [
        'table_prefix' => $tablePrefix,
        'tables' => $tables,
        'json_column_type' => env('AFFILIATES_JSON_COLUMN_TYPE', 'jsonb'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Commission calculations store amounts in the smallest unit (cents).
    | Configure the fallback ISO currency and rounding precision (basis points
    | for percentage commissions).
    |
    */

    'currency' => [
        'default' => env('AFFILIATES_DEFAULT_CURRENCY', 'MYR'),
        'percentage_scale' => env('AFFILIATES_PERCENTAGE_SCALE', 100), // basis points (100 = 1%)
    ],

    /*
    |--------------------------------------------------------------------------
    | Features / Behavior
    |--------------------------------------------------------------------------
    */

    'features' => [
        'cart_integration' => [
            'enabled' => env('AFFILIATES_FEATURE_CART_INTEGRATION', true),
        ],
        'voucher_integration' => [
            'enabled' => env('AFFILIATES_FEATURE_VOUCHER_INTEGRATION', true),
        ],
        'commission_tracking' => [
            'enabled' => env('AFFILIATES_FEATURE_COMMISSION_TRACKING', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Managed notifications via aiarmada/communications when installed
    | (program joins, conversions). On by default; silent when the
    | communications package is absent regardless of this flag.
    |
    */
    'notifications' => [
        'enabled' => env('AFFILIATES_NOTIFICATIONS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ownership (Multi-Tenancy)
    |--------------------------------------------------------------------------
    |
    | When enabled, affiliates/attributions/conversions are automatically scoped
    | to the current owner. The OwnerResolverInterface binding is provided by
    | commerce-support.
    |
    */

    'owner' => [
        'enabled' => env('AFFILIATES_OWNER_ENABLED', false),
        'include_global' => env('AFFILIATES_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('AFFILIATES_OWNER_AUTO_ASSIGN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cart Integration
    |--------------------------------------------------------------------------
    |
    | Affiliate attribution is stored in normalized tables. Cart attribution
    | hydration is exposed through the CartBridge integration.
    |
    */

    'cart' => [
        // Enable affiliate customer discounts as cart conditions
        'customer_discounts_enabled' => env('AFFILIATES_CUSTOMER_DISCOUNTS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cookie Tracking
    |--------------------------------------------------------------------------
    |
    | Persist affiliate visits even before a cart exists by dropping a tracking
    | cookie. Configure the cookie name, lifetime, and auto-registration of the
    | middleware that captures affiliate query parameters.
    |
    */

    'cookies' => [
        'enabled' => env('AFFILIATES_COOKIES_ENABLED', true),
        'name' => env('AFFILIATES_COOKIE_NAME', 'affiliate_session'),
        'ttl_minutes' => env('AFFILIATES_COOKIE_TTL_MINUTES', 60 * 24 * 30),
        'path' => env('AFFILIATES_COOKIE_PATH', '/'),
        'domain' => env('AFFILIATES_COOKIE_DOMAIN'),
        'secure' => env('AFFILIATES_COOKIE_SECURE', true),
        'http_only' => env('AFFILIATES_COOKIE_HTTP_ONLY', true),
        'same_site' => env('AFFILIATES_COOKIE_SAME_SITE', 'lax'),
        'query_parameters' => ['aff', 'affiliate', 'ref', 'referral'],
        'auto_register_middleware' => env('AFFILIATES_COOKIES_AUTO_MIDDLEWARE', true),
        'respect_dnt' => env('AFFILIATES_COOKIES_RESPECT_DNT', false),
        'require_consent' => env('AFFILIATES_COOKIES_REQUIRE_CONSENT', false),
        'consent_cookie' => env('AFFILIATES_COOKIES_CONSENT_COOKIE', 'affiliate_consent'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Pages
    |--------------------------------------------------------------------------
    |
    | Provide package-owned primitives for public referral entry routes,
    | request-scoped referral context, and shared banner data on public pages.
    |
    */

    'public_pages' => [
        'enabled' => env('AFFILIATES_PUBLIC_PAGES_ENABLED', true),
        'view_data_key' => env('AFFILIATES_PUBLIC_PAGES_VIEW_DATA_KEY', 'affiliateReferral'),
        'auto_register_middleware' => env('AFFILIATES_PUBLIC_PAGES_AUTO_MIDDLEWARE', true),
        'route' => [
            'enabled' => env('AFFILIATES_PUBLIC_REFERRAL_ROUTE_ENABLED', true),
            'path' => env('AFFILIATES_PUBLIC_REFERRAL_ROUTE_PATH', 'r/{affiliateCode}'),
            'name' => env('AFFILIATES_PUBLIC_REFERRAL_ROUTE_NAME', 'affiliate.referral.entry'),
            'middleware' => array_values(array_filter(array_map('trim', explode(',', (string) env('AFFILIATES_PUBLIC_REFERRAL_ROUTE_MIDDLEWARE', 'web'))))),
            'destination_parameter' => env('AFFILIATES_PUBLIC_REFERRAL_DESTINATION_PARAMETER', 'to'),
            'destinations' => [
                'home' => env('AFFILIATES_PUBLIC_REFERRAL_HOME_PATH', '/'),
                'checkout' => env('AFFILIATES_PUBLIC_REFERRAL_CHECKOUT_PATH', '/checkout'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Voucher Integration
    |--------------------------------------------------------------------------
    */

    'integrations' => [
        'vouchers' => [
            'attach_on_apply' => env('AFFILIATES_ATTACH_ON_VOUCHER', true),
            'match_default_voucher_code' => env('AFFILIATES_MATCH_DEFAULT_VOUCHER', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Commission Settings
    |--------------------------------------------------------------------------
    */

    'commissions' => [
        'auto_approve' => env('AFFILIATES_AUTO_APPROVE', false),
        'default_status' => 'pending',
        // 10% = 1000 basis points
        'default_rate' => env('AFFILIATES_DEFAULT_COMMISSION_RATE', 1000),
        'minimum_minor' => env('AFFILIATES_MINIMUM_COMMISSION_MINOR', 0),
        'maximum_minor' => env('AFFILIATES_MAXIMUM_COMMISSION_MINOR'),
    ],

    'payouts' => [
        'currency' => 'MYR',
        'reference_prefix' => env('AFFILIATES_PAYOUT_REF_PREFIX', 'PO-'),
        'minimum_amount' => 5000,
        'minimum_amounts_by_currency' => [],
        'maturity_days' => 30,
        'reward_award_settlement' => [
            'enabled' => false,
        ],
        'multi_level' => [
            'enabled' => false,
            'levels' => array_filter(array_map('floatval', explode(',', (string) env('AFFILIATES_MULTI_LEVEL_LEVELS', '0.1,0.05')))), // percentages of commission (0.1 = 10%)
        ],
        'paypal' => [
            'client_id' => env('AFFILIATES_PAYPAL_CLIENT_ID', ''),
            'client_secret' => env('AFFILIATES_PAYPAL_CLIENT_SECRET', ''),
            'sandbox' => env('AFFILIATES_PAYPAL_SANDBOX', true),
        ],
        'stripe' => [
            'secret_key' => env('AFFILIATES_STRIPE_SECRET_KEY', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking Defaults
    |--------------------------------------------------------------------------
    */

    'tracking' => [
        'attribution_ttl_days' => env('AFFILIATES_ATTRIBUTION_TTL_DAYS', 30),
        'max_attributions_per_identifier' => env('AFFILIATES_ATTRIBUTION_MAX', 5),
        'block_self_referral' => env('AFFILIATES_BLOCK_SELF_REFERRAL', true),
        'ip_rate_limit' => [
            'enabled' => env('AFFILIATES_IP_RATE_LIMIT_ENABLED', false),
            'max' => env('AFFILIATES_IP_RATE_LIMIT_MAX', 20),
            'decay_minutes' => env('AFFILIATES_IP_RATE_LIMIT_DECAY', 30),
        ],
        'attribution_model' => env('AFFILIATES_ATTRIBUTION_MODEL', 'last_touch'), // last_touch, first_touch, linear
        'fingerprint' => [
            'enabled' => env('AFFILIATES_FINGERPRINT_ENABLED', true),
            'block_duplicates' => env('AFFILIATES_FINGERPRINT_BLOCK_DUPLICATES', false),
            'threshold' => env('AFFILIATES_FINGERPRINT_THRESHOLD', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    'events' => [
        'dispatch_attributed' => env('AFFILIATES_EVENT_ATTRIBUTED', true),
        // Open-registration auto-approval listens for this event: disabling
        // it leaves open-mode affiliates pending forever.
        'dispatch_conversion' => env('AFFILIATES_EVENT_CONVERSION', true),
        'dispatch_webhooks' => env('AFFILIATES_EVENT_WEBHOOKS', false),
    ],

    /*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
*/

    'webhooks' => [
        'signature_secret' => env('AFFILIATES_WEBHOOK_SIGNATURE_SECRET'),
        'endpoints' => [
            'attribution' => explode(',', (string) env('AFFILIATES_WEBHOOKS_ATTRIBUTION', '')),
            'conversion' => explode(',', (string) env('AFFILIATES_WEBHOOKS_CONVERSION', '')),
            'payout' => explode(',', (string) env('AFFILIATES_WEBHOOKS_PAYOUT', '')),
        ],
        'headers' => [
            // Additional signature header used by dispatched webhook requests.
            'X-Affiliates-Signature' => env('AFFILIATES_WEBHOOKS_SIGNATURE'),
        ],
    ],

    /*
|--------------------------------------------------------------------------
| Links
|--------------------------------------------------------------------------
*/

    // Public Handles
    'handles' => [
        'allow_custom' => true,
    ],

    'links' => [
        'default_style' => 'short',
        'parameter' => env('AFFILIATES_LINK_PARAM', 'aff'),
        'allowed_hosts' => array_filter(explode(',', (string) env('AFFILIATES_LINK_ALLOWED_HOSTS', ''))),
    ],

    /*
|--------------------------------------------------------------------------
| Public API
|--------------------------------------------------------------------------
*/

    'api' => [
        'enabled' => env('AFFILIATES_API_ENABLED', false),
        'prefix' => env('AFFILIATES_API_PREFIX', 'api/affiliates'),
        'middleware' => ['api', 'throttle:60,1'],
        'token' => env('AFFILIATES_API_TOKEN'), // static bearer token, required
    ],

    /*
    |--------------------------------------------------------------------------
    | Affiliate Network Merchant SDK
    |--------------------------------------------------------------------------
    |
    | Connection details for reporting sales to an affiliate network via
    | NetworkPostbackClient, plus the referral-capture settings used by the
    | CaptureNetworkReferral middleware. Only needed on merchant stores
    | that sell through a network marketplace.
    |
    */

    'merchant' => [
        'network_url' => env('AFFILIATE_NETWORK_URL'),
        'prefix' => env('AFFILIATE_NETWORK_PREFIX', 'api/affiliate-network'),
        'site' => env('AFFILIATE_NETWORK_SITE'),
        'token' => env('AFFILIATE_NETWORK_TOKEN'),
        'referral_param' => env('AFFILIATE_NETWORK_REFERRAL_PARAM', 'anl'),
        'session_key' => env('AFFILIATE_NETWORK_SESSION_KEY', 'affiliate_network.link_code'),
        'timeout_seconds' => env('AFFILIATE_NETWORK_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    */

    'tax' => [
        'storage_disk' => env('AFFILIATES_TAX_STORAGE_DISK', 'local'),
        // Stored/compared in minor units (e.g. 60000 = $600.00)
        '1099_threshold' => env('AFFILIATES_TAX_1099_THRESHOLD', 60000),
        '1099_threshold_currency' => env('AFFILIATES_TAX_1099_THRESHOLD_CURRENCY', 'USD'),
        'payer_info' => [
            'name' => env('AFFILIATES_TAX_PAYER_NAME', env('APP_NAME', 'Laravel')),
            'address' => env('AFFILIATES_TAX_PAYER_ADDRESS', ''),
            'tin' => env('AFFILIATES_TAX_PAYER_TIN', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bonuses
    |--------------------------------------------------------------------------
    */

    'bonuses' => [
        'top_performer' => [
            'enabled' => false,
            'positions' => [ // minor units, paid in each affiliate's own currency
                1 => env('AFFILIATES_BONUS_TOP_PERFORMER_1_MINOR', 50000),
                2 => env('AFFILIATES_BONUS_TOP_PERFORMER_2_MINOR', 25000),
                3 => env('AFFILIATES_BONUS_TOP_PERFORMER_3_MINOR', 10000),
            ],
            'min_revenue' => env('AFFILIATES_BONUS_TOP_PERFORMER_MIN_REVENUE_MINOR', 100000),
            'min_revenue_currency' => env('AFFILIATES_BONUS_TOP_PERFORMER_MIN_REVENUE_CURRENCY', env('AFFILIATES_DEFAULT_CURRENCY', 'MYR')),
        ],
        'recruitment' => [
            'enabled' => false,
            'bonus_per_recruit' => env('AFFILIATES_BONUS_RECRUITMENT_PER_RECRUIT_MINOR', 2500), // minor units, paid in each affiliate's own currency
            'min_recruits' => env('AFFILIATES_BONUS_RECRUITMENT_MIN_RECRUITS', 3),
            'max_bonus' => env('AFFILIATES_BONUS_RECRUITMENT_MAX_MINOR', 25000), // minor units, paid in each affiliate's own currency
        ],
        'consistency' => [
            'enabled' => false,
            'bonus_amount' => env('AFFILIATES_BONUS_CONSISTENCY_AMOUNT_MINOR', 5000), // minor units, paid in each affiliate's own currency
            'min_weeks' => env('AFFILIATES_BONUS_CONSISTENCY_MIN_WEEKS', 4),
            'min_conversions_per_week' => env('AFFILIATES_BONUS_CONSISTENCY_MIN_CONVERSIONS_PER_WEEK', 1),
        ],
        'growth' => [
            'enabled' => false,
            'bonus_amount' => env('AFFILIATES_BONUS_GROWTH_AMOUNT_MINOR', 10000), // minor units, paid in each affiliate's own currency
            'min_growth_percent' => env('AFFILIATES_BONUS_GROWTH_MIN_PERCENT', 25),
            'min_previous_revenue' => env('AFFILIATES_BONUS_GROWTH_MIN_PREVIOUS_REVENUE_MINOR', 50000),
            'min_previous_revenue_currency' => env('AFFILIATES_BONUS_GROWTH_MIN_PREVIOUS_REVENUE_CURRENCY', env('AFFILIATES_DEFAULT_CURRENCY', 'MYR')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fraud Detection
    |--------------------------------------------------------------------------
    */

    'fraud' => [
        'enabled' => env('AFFILIATES_FRAUD_ENABLED', true),
        'blocking_threshold' => env('AFFILIATES_FRAUD_BLOCK_THRESHOLD', 100),

        'velocity' => [
            'enabled' => env('AFFILIATES_FRAUD_VELOCITY_ENABLED', true),
            'max_clicks_per_hour' => env('AFFILIATES_FRAUD_MAX_CLICKS_HOUR', 100),
            'max_conversions_per_day' => env('AFFILIATES_FRAUD_MAX_CONVERSIONS_DAY', 50),
        ],

        'anomaly' => [
            'geo' => [
                'enabled' => env('AFFILIATES_FRAUD_GEO_ENABLED', false),
            ],
            'conversion_time' => [
                'min_seconds' => env('AFFILIATES_FRAUD_MIN_CONVERSION_SECONDS', 5),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upline / MLM Settings
    |--------------------------------------------------------------------------
    */

    'upline' => [
        'enabled' => false,
        'max_depth' => env('AFFILIATES_UPLINE_MAX_DEPTH', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration & Approval
    |--------------------------------------------------------------------------
    |
    | Configure self-registration for affiliates. The owner model determines
    | the polymorphic relationship owner. Approval mode controls how new
    | affiliate registrations are handled.
    |
    */

    'registration' => [
        'enabled' => env('AFFILIATES_REGISTRATION_ENABLED', true),
        'approval_mode' => env('AFFILIATES_REGISTRATION_APPROVAL_MODE', 'admin'), // auto | open | admin; read at signup, snapshotted per affiliate
        'default_commission_type' => env('AFFILIATES_REGISTRATION_COMMISSION_TYPE', 'percentage'),
        'default_commission_rate' => env('AFFILIATES_REGISTRATION_COMMISSION_RATE', 1000), // 10% in basis points
        'open_approval_min_commission_minor' => env('AFFILIATES_OPEN_APPROVAL_MIN_COMMISSION', 0), // qualifying conversion must exceed this
    ],

    /*
    |--------------------------------------------------------------------------
    | Program Catalog (network sync source)
    |--------------------------------------------------------------------------
    |
    | Read-only snapshot consumed by affiliate-network (shared DB or HTTP).
    | max_subjects caps snapshot size per program; paginate later if needed.
    |
    */

    'catalog' => [
        'enabled' => env('AFFILIATES_CATALOG_ENABLED', true),
        'max_subjects' => env('AFFILIATES_CATALOG_MAX_SUBJECTS', 500),
    ],
];
