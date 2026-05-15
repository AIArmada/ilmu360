<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;

it('seeds the canonical ilmu360.com admin user accounts', function (): void {
    $this->seed([
        PermissionSeeder::class,
        RoleSeeder::class,
        UserSeeder::class,
    ]);

    expect(User::query()->pluck('email')->all())
        ->toContain(
            'superadmin@ilmu360.com',
            'admin@ilmu360.com',
            'moderator@ilmu360.com',
            'editor@ilmu360.com',
            'viewer@ilmu360.com',
            'user@ilmu360.com',
        )
        ->not->toContain(
            'superadmin@ilmu360.my',
            'admin@ilmu360.my',
            'moderator@ilmu360.my',
            'editor@ilmu360.my',
            'viewer@ilmu360.my',
            'user@ilmu360.my',
        );
});