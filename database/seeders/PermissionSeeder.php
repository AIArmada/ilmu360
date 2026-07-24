<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\CommerceSupport\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'feedback.blocked',
            'institution.view',
            'institution.update',
            'institution.delete',
            'institution.manage-members',
            'institution.manage-donation-channels',
            'person.view',
            'person.update',
            'person.delete',
            'person.manage-members',
            'event.view',
            'event.create',
            'event.update',
            'event.delete',
            'event.manage-members',
            'event.view-registrations',
            'event.export-registrations',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }
}
