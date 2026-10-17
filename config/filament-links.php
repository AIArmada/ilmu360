<?php

declare(strict_types=1);
use AIArmada\Affiliates\Models\AffiliateLink;

return [
    'staff_roles' => ['super_admin', 'admin'],
    'protected_subject_types' => [AffiliateLink::class],

    /* Navigation */
    'navigation' => [
        'group' => 'Links',
    ],

    /* Features */
    'features' => [
        'links' => true,
    ],

    /* Resources */
    'resources' => [
        'navigation_sort' => [
            'links' => 10,
        ],
    ],
];
