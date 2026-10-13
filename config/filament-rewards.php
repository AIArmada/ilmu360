<?php

declare(strict_types=1);
use AIArmada\Organizations\Models\Organization;
use App\Models\Institution;

return [

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    'navigation' => [
        'group' => 'Rewards',

        'sort' => [
            'programs' => 1,
            'awards' => 2,
            'appeals' => 3,
            'funding' => 10,
            'payees' => 11,
            'payouts' => 12,
            'benefit_items' => 13,
            'benefit_grants' => 14,
            'recoveries' => 15,
            'fraud' => 16,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff
    |--------------------------------------------------------------------------
    */

    'staff_roles' => ['super_admin', 'admin'],

    /*
    |--------------------------------------------------------------------------
    | Owners
    |--------------------------------------------------------------------------
    */

    'owners' => [
        'types' => [
            'organization' => ['model' => Organization::class, 'label' => 'Organization', 'name_column' => 'name'],
            'institution' => ['model' => Institution::class, 'label' => 'Institution', 'name_column' => 'name'],
        ],
    ],

];
