<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\FilamentAuthz\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates roles for the application. Role descriptions are documented here
     * for reference but not stored in the database:
     * - super_admin: Full access to all features and settings
     * - admin: Administrative access with most permissions
     * - moderator: Can moderate content and approve submissions
     * - editor: Can create and edit content
     * - viewer: Read-only access to admin panel
     */
    public function run(): void
    {
        $roles = [
            'super_admin',
            'admin',
            'moderator',
            'editor',
            'viewer',
        ];

        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
