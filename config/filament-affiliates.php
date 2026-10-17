<?php

declare(strict_types=1);

return [
    /* Navigation */

    'navigation' => [
        'group' => 'E-commerce',
    ],

    /* Widgets */

    'widgets' => [
        'currency' => env('AFFILIATES_DEFAULT_CURRENCY', 'MYR'),
    ],

    /* Features */

    'features' => [
        'admin' => [
            'conversions' => true,
            'payouts' => true,
            'programs' => true,
            'commission_management' => true,
            'volume_tiers' => false,
            'links' => true,
            'attribution' => true,
            'ranks' => false,
            'support_compliance' => true,
            'fraud_monitoring' => true,
            'reports' => true,
            'upline_visualization' => false,
            'creatives' => true,
        ],
    ],

    /* Portal */

    'portal' => [
        'panel_id' => env('AFFILIATES_PORTAL_PANEL_ID', 'affiliate'),
        'path' => env('AFFILIATES_PORTAL_PATH', 'affiliate'),
        'domain' => env('AFFILIATES_PORTAL_DOMAIN'),
        'brand_name' => env('AFFILIATES_PORTAL_BRAND_NAME', 'Affiliate Portal'),
        'primary_color' => env('AFFILIATES_PORTAL_PRIMARY_COLOR', '#6366f1'),
        'login_enabled' => env('AFFILIATES_PORTAL_LOGIN_ENABLED', true),
        'registration_enabled' => env('AFFILIATES_PORTAL_REGISTRATION_ENABLED', true),
        'auth_guard' => env('AFFILIATES_PORTAL_AUTH_GUARD', 'web'),
        'features' => [
            'dashboard' => true,
            'profile' => true,
            'links' => true,
            'programs' => true,
            'downlines' => true,
            'conversions' => true,
            'payouts' => true,
            'support_compliance' => true,
            'creatives' => true,
            'vouchers' => true,
        ],
        'navigation_sort' => [
            'dashboard' => -2,
            'profile' => -1,
            'links' => 1,
            'programs' => 2,
            'conversions' => 2,
            'creatives' => 2,
            'downlines' => 3,
            'payouts' => 3,
            'vouchers' => 3,
            'support' => 5,
        ],
    ],

    /* Integrations */

    'integrations' => [
        'filament_cart' => true,
        'filament_vouchers' => true,
    ],

    /* Resources */

    'resources' => [
        'navigation_sort' => [
            'affiliates' => 60,
            'affiliate_conversions' => 61,
            'affiliate_payouts' => 62,
            'affiliate_programs' => 63,
            'affiliate_commission_templates' => 64,
            'affiliate_creatives' => 65,
            'affiliate_fraud_signals' => 66,
            'affiliate_links' => 67,
            'affiliate_touchpoints' => 68,
            'affiliate_ranks' => 69,
            'affiliate_upline' => 70,
            'affiliate_rank_histories' => 71,
            'affiliate_support_tickets' => 72,
            'affiliate_tax_documents' => 73,
            'affiliate_volume_tiers' => 74,
        ],
    ],

    'pages' => [
        'navigation_sort' => [
            'reports' => 10,
            'commission_settings' => 5,
            'bonus_settings' => 6,
            'payout_batch' => 12,
            'payout_settings' => 13,
            'performance_bonuses' => 14,
            'fraud_review' => 15,
        ],
    ],
];
