<?php

use AIArmada\CommerceSupport\Models\Role;
use AIArmada\Contacting\Enums\SocialPlatform;
use App\Models\Institution;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

it('loads the edit institution page with social media items', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $institution = Institution::factory()->create();
    $institution->socialProfiles()->create([
        'platform' => SocialPlatform::Facebook->value,
        'url' => 'https://facebook.com/ilmu360',
        'handle' => 'ilmu360',
    ]);

    $this->actingAs($user)
        ->get(route('filament.admin.resources.institutions.edit', $institution))
        ->assertSuccessful();
});
