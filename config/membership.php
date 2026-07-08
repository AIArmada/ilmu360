<?php

declare(strict_types=1);

return [
    'role_mapping' => [
        'admin' => env('MEMBERSHIP_ROLE_ADMIN_NAME', 'admin'),
        'editor' => env('MEMBERSHIP_ROLE_EDITOR_NAME', 'editor'),
        'viewer' => env('MEMBERSHIP_ROLE_VIEWER_NAME', 'viewer'),
    ],
];
