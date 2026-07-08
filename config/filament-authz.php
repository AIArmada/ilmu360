<?php

declare(strict_types=1);

return [
    'super_admin_role' => 'super_admin',

    'wildcard_permissions' => true,

    'central_app' => true,

    'authz_scopes' => [
        'enabled' => true,
        'auto_create' => true,
    ],

    'permissions' => [
        'separator' => '.',
        'case' => 'camel',
    ],

    'custom_permissions' => [],

    'sync' => [
        'permissions' => [],
        'roles' => [],
    ],

    'role_resource' => [
        'slug' => 'authz/roles',
        'tabs' => [
            'resources' => true,
            'pages' => true,
            'widgets' => true,
            'custom_permissions' => true,
            'direct_permissions' => true,
        ],
        'grid_columns' => 2,
        'checkbox_columns' => 3,
        'section_column_span' => 1,
    ],

    'user_resource' => [
        'enabled' => true,
        'auto_register' => false,
        'model' => null,
        'slug' => 'authz/users',
        'navigation' => [
            'group' => 'Authz',
            'sort' => 98,
            'icon' => 'heroicon-o-user-group',
        ],
        'form' => [
            'fields' => ['name', 'email', 'phone', 'timezone', 'email_verified_at', 'phone_verified_at', 'password'],
            'roles' => true,
            'permissions' => true,
        ],
    ],

    'impersonate' => [
        'enabled' => true,
        'guard' => 'web',
    ],
];
