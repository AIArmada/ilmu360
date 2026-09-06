<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Super Admin - Full access to everything
        $superAdmin = User::query()->updateOrCreate(
            ['email' => 'superadmin@ilmu360.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
            ]
        );
        $this->markEmailAsVerified($superAdmin);
        $superAdmin->syncRoles(['super_admin']);

        // Admin - Administrative access
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@ilmu360.com'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('password'),
            ]
        );
        $this->markEmailAsVerified($admin);
        $admin->syncRoles(['admin']);

        // Moderator - Can moderate content
        $moderator = User::query()->updateOrCreate(
            ['email' => 'moderator@ilmu360.com'],
            [
                'name' => 'Content Moderator',
                'password' => Hash::make('password'),
            ]
        );
        $this->markEmailAsVerified($moderator);
        $moderator->syncRoles(['moderator']);

        // Editor - Can create and edit content
        $editor = User::query()->updateOrCreate(
            ['email' => 'editor@ilmu360.com'],
            [
                'name' => 'Content Editor',
                'password' => Hash::make('password'),
            ]
        );
        $this->markEmailAsVerified($editor);
        $editor->syncRoles(['editor']);

        // Viewer - Read-only access
        $viewer = User::query()->updateOrCreate(
            ['email' => 'viewer@ilmu360.com'],
            [
                'name' => 'Report Viewer',
                'password' => Hash::make('password'),
            ]
        );
        $this->markEmailAsVerified($viewer);
        $viewer->syncRoles(['viewer']);

        // Regular user without admin access
        $user = User::query()->updateOrCreate(
            ['email' => 'user@ilmu360.com'],
            [
                'name' => 'Regular User',
                'password' => Hash::make('password'),
            ]
        );
        $this->markEmailAsVerified($user);

        $this->command->info('User accounts seeded successfully!');
        $this->command->newLine();
        $this->command->table(
            ['Email', 'Password', 'Role'],
            [
                ['superadmin@ilmu360.com', 'password', 'super_admin'],
                ['admin@ilmu360.com', 'password', 'admin'],
                ['moderator@ilmu360.com', 'password', 'moderator'],
                ['editor@ilmu360.com', 'password', 'editor'],
                ['viewer@ilmu360.com', 'password', 'viewer'],
                ['user@ilmu360.com', 'password', '(none)'],
            ]
        );
    }

    private function markEmailAsVerified(User $user): void
    {
        $user->forceFill(['email_verified_at' => now()])->save();
    }
}
