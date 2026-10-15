<?php

declare(strict_types=1);

return [
    'database' => [
        'json_column_type' => env('REFERRALS_JSON_COLUMN_TYPE', 'jsonb'),
        'tables' => [
            'programs' => 'referral_programs',
            'invites' => 'referral_invites',
            'rewards' => 'referral_rewards',
        ],
    ],

    'defaults' => [
        'currency' => 'MYR',
        'invite_expiry_days' => 30,
        'token_length' => 32,
    ],

    'owner' => [
        'enabled' => (bool) env('REFERRALS_OWNER_ENABLED', false),
        'include_global' => false,
        'auto_assign_on_create' => true,
        'owner_type_column' => 'owner_type',
        'owner_id_column' => 'owner_id',
    ],

    'fulfillment' => [
        'default_driver' => 'manual',
        'drivers' => [
            'manual' => [
                'enabled' => true,
                'reference_metadata_key' => 'external_reference',
            ],
            'voucher' => [
                'enabled' => true,
            ],
            'affiliate_cash' => [
                'enabled' => false,
            ],
            'reward_points' => [
                'enabled' => true,
            ],
        ],
    ],
];
