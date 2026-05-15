<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */
    'navigation' => [
        'roles' => 'Peranan',
        'permissions' => 'Kebenaran',
        'group' => 'Tetapan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Labels
    |--------------------------------------------------------------------------
    */
    'resource' => [
        'role' => [
            'label' => 'Peranan',
            'plural_label' => 'Peranan',
        ],
        'permission' => [
            'label' => 'Kebenaran',
            'plural_label' => 'Kebenaran',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Form Fields
    |--------------------------------------------------------------------------
    */
    'form' => [
        'name' => 'Nama',
        'name_placeholder' => 'Masukkan nama peranan',
        'name_helper' => 'Pengecam unik untuk peranan ini',
        'guard_name' => 'Guard',
        'guard_name_helper' => 'Guard pengesahan yang digunakan oleh peranan ini',
        'scope' => 'Skop',
        'scope_helper' => 'Biarkan kosong untuk peranan global.',
        'permissions' => 'Kebenaran',
        'direct_permissions' => 'Kebenaran Tambahan',
        'direct_permissions_helper' => 'Tetapkan kebenaran aplikasi (bukan Filament resource/page/widget) secara terus kepada peranan ini.',
        'team' => 'Pasukan',
        'team_placeholder' => 'Pilih pasukan...',
        'select_all' => 'Pilih Semua',
        'select_all_message' => 'Aktif/Nyahaktif semua kebenaran untuk peranan ini',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Columns
    |--------------------------------------------------------------------------
    */
    'table' => [
        'name' => 'Nama',
        'guard_name' => 'Guard',
        'scope' => 'Skop',
        'global_scope' => 'Global',
        'permissions_count' => 'Kebenaran',
        'created_at' => 'Dicipta',
        'updated_at' => 'Dikemas kini',
    ],

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */
    'filter' => [
        'guard' => 'Guard',
        'all_guards' => 'Semua Guard',
        'scope' => 'Skop',
        'all_scopes' => 'Semua Skop',
        'global_scope' => 'Global',
        'has_permissions' => 'Mempunyai Kebenaran',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tabs
    |--------------------------------------------------------------------------
    */
    'tabs' => [
        'resources' => 'Sumber',
        'pages' => 'Halaman',
        'widgets' => 'Widget',
        'custom' => 'Tersuai',
        'direct_permissions' => 'Kebenaran Tambahan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */
    'search' => [
        'resources' => 'Cari Sumber',
        'resources_placeholder' => 'Taip untuk menapis sumber...',
        'clear_resources' => 'Kosongkan carian sumber',
        'pages' => 'Cari Halaman',
        'pages_placeholder' => 'Taip untuk menapis halaman...',
        'clear_pages' => 'Kosongkan carian halaman',
        'widgets' => 'Cari Widget',
        'widgets_placeholder' => 'Taip untuk menapis widget...',
        'clear_widgets' => 'Kosongkan carian widget',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sections
    |--------------------------------------------------------------------------
    */
    'section' => [
        'role_details' => 'Butiran Peranan',
        'role_details_description' => 'Tetapkan nama peranan dan guard yang berkaitan.',
        'resources_count' => ':count sumber|:count sumber',
        'permissions_count' => ':count kebenaran|:count kebenaran',
        'pages_count' => ':count halaman|:count halaman',
        'widgets_count' => ':count widget|:count widget',
        'pages' => 'Kebenaran Halaman',
        'pages_description' => 'Kawal akses ke setiap halaman.',
        'widgets' => 'Kebenaran Widget',
        'widgets_description' => 'Kawal keterlihatan widget papan pemuka.',
        'custom' => 'Kebenaran Tersuai',
        'custom_description' => 'Kebenaran tambahan khusus aplikasi.',
        'direct_permissions' => 'Kebenaran Tambahan',
        'direct_permissions_description' => 'Urus kebenaran aplikasi tambahan yang tidak dipaparkan dalam tab Resource/Halaman/Widget.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Empty State
    |--------------------------------------------------------------------------
    */
    'empty_state' => [
        'heading' => 'Belum ada peranan',
        'description' => 'Cipta peranan untuk mengurus kebenaran akses pengguna anda.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notification' => [
        'role_created' => 'Peranan berjaya dicipta',
        'role_updated' => 'Peranan berjaya dikemas kini',
        'role_deleted' => 'Peranan berjaya dipadam',
        'permissions_synced' => 'Kebenaran berjaya disegerakkan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */
    'forbidden' => 'Anda tidak mempunyai kebenaran untuk mengakses sumber ini.',

    /*
    |--------------------------------------------------------------------------
    | Resource Permission Prefixes
    |--------------------------------------------------------------------------
    */
    'resource_permission_prefixes' => [
        'view' => 'Lihat',
        'viewAny' => 'Lihat Semua',
        'create' => 'Cipta',
        'update' => 'Kemas Kini',
        'delete' => 'Padam',
        'deleteAny' => 'Padam Semua',
        'forceDelete' => 'Padam Kekal',
        'forceDeleteAny' => 'Padam Semua Secara Kekal',
        'restore' => 'Pulih',
        'restoreAny' => 'Pulih Semua',
        'reorder' => 'Susun Semula',
        'replicate' => 'Gandakan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Commands
    |--------------------------------------------------------------------------
    */
    'command' => [
        'discover' => [
            'description' => 'Temui resource, halaman, dan widget Filament',
        ],
        'sync' => [
            'description' => 'Segerakkan kebenaran daripada entiti yang ditemui ke pangkalan data',
        ],
        'super_admin' => [
            'description' => 'Tetapkan peranan super admin kepada pengguna',
        ],
        'seeder' => [
            'description' => 'Jana seeder untuk peranan dan kebenaran sedia ada',
        ],
        'policies' => [
            'description' => 'Jana kelas polisi untuk resource Filament',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Impersonation
    |--------------------------------------------------------------------------
    */
    'impersonate' => [
        'action' => 'Menyamar',
        'modal_heading' => 'Menyamar sebagai Pengguna',
        'modal_description' => 'Anda akan menyamar sebagai pengguna ini. Anda akan log masuk sebagai mereka dan boleh melakukan tindakan bagi pihak mereka.',
        'confirm' => 'Mulakan Penyamaran',
        'leave' => 'Keluar Penyamaran',
        'banner_message' => 'Anda sedang menyamar sebagai :name.',
        'left_message' => 'Anda telah keluar daripada penyamaran dan kembali ke akaun anda.',
        'redirect_label' => 'Arahkan Ke',
        'redirect_helper' => 'Pilih destinasi selepas menyamar sebagai pengguna ini.',
        'redirect_frontpage' => 'Halaman Utama',
        'redirect_panel_suffix' => 'Panel',
    ],
];
