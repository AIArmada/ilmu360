<?php

declare(strict_types=1);
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

return [
    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */
    'guards' => ['web', 'api'],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    */
    'super_admin_role' => 'super_admin',

    'panel_user' => [
        'enabled' => false,
        'name' => 'panel_user',
    ],

    'wildcard_permissions' => true,

    'scoped_to_tenant' => true,

    'central_app' => true,

    'authz_scopes' => [
        'enabled' => true,
        'auto_create' => true,
    ],

    'permissions' => [
        'separator' => '.',
        'case' => 'camel',
    ],

    'resources' => [
        'subject' => 'model',
        'actions' => ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'],
        'extra_actions' => [],
        'action_labels' => [],
        'exclude' => [],
    ],

    'pages' => [
        'prefix' => 'page',
        'exclude' => [
            Dashboard::class,
        ],
    ],

    'widgets' => [
        'prefix' => 'widget',
        'exclude' => [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ],
    ],

    'custom_permissions' => [],

    'sync' => [
        'permissions' => [],
        'roles' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */
    'navigation' => [
        'register' => true,
        'group' => 'Authz',
        'sort' => 99,
        'label' => null,
        'badge' => null,
        'badge_color' => null,
        'parent_item' => null,
        'cluster' => null,
        'icons' => [
            'roles' => 'heroicon-o-shield-check',
            'roles_active' => null,
            'permissions' => 'heroicon-o-key',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | Impersonation
    |--------------------------------------------------------------------------
    |
    | - enabled: Enable/disable impersonation feature
    | - guard: The authentication guard to use for impersonation
    | - Redirect destination is selected in the modal form
    | - Leave impersonation always returns to origin panel
    */
    'impersonate' => [
        'enabled' => true,
        'guard' => 'web',
    ],
];
