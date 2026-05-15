<?php

declare(strict_types=1);

return [
    'default' => 'malaysia',

    'countries' => [
        'malaysia' => [
            'label' => 'Malaysia',
            'flag' => '🇲🇾',
            'iso2' => 'MY',
            'default_timezone' => 'Asia/Kuala_Lumpur',
            'timezones' => ['Asia/Kuala_Lumpur'],
            'enabled' => true,
            'coming_soon' => false,
        ],
        'brunei' => [
            'label' => 'Brunei',
            'flag' => '🇧🇳',
            'iso2' => 'BN',
            'default_timezone' => 'Asia/Brunei',
            'timezones' => ['Asia/Brunei'],
            'enabled' => false,
            'coming_soon' => true,
        ],
        'singapore' => [
            'label' => 'Singapore',
            'flag' => '🇸🇬',
            'iso2' => 'SG',
            'default_timezone' => 'Asia/Singapore',
            'timezones' => ['Asia/Singapore'],
            'enabled' => false,
            'coming_soon' => true,
        ],
        'indonesia' => [
            'label' => 'Indonesia',
            'flag' => '🇮🇩',
            'iso2' => 'ID',
            'default_timezone' => 'Asia/Jakarta',
            'timezones' => ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'],
            'enabled' => false,
            'coming_soon' => true,
        ],
    ],
];
