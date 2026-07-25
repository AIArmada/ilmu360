<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Directory',
    ],
    'resources' => [
        'enabled' => [
            'person' => false,
            'title' => true,
            'title_issuer' => true,
            'credential_definition' => true,
        ],
        'navigation_sort' => [
            'person' => 1,
            'title' => 10,
            'title_issuer' => 11,
            'credential_definition' => 12,
        ],
    ],
];
