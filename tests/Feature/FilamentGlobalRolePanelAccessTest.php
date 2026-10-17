<?php

use App\Filament\Pages\AdminDashboard;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Panel;

it('allows a moderator with a global role to enter the admin panel', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    expect($moderator->canAccessPanel(Panel::make()->id('admin')))->toBeTrue();

    $this->actingAs($moderator)
        ->get(AdminDashboard::getUrl(panel: 'admin'))
        ->assertOk();
});

it('redirects a fresh admin login to the admin dashboard', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('admin');
    $dashboardUrl = AdminDashboard::getUrl(panel: 'admin');

    $this->get($dashboardUrl)->assertRedirect();

    $this->post(route('login'), [
        'email' => $administrator->email,
        'password' => 'password',
    ])->assertRedirect($dashboardUrl);

    $this->get($dashboardUrl)->assertOk();
});
