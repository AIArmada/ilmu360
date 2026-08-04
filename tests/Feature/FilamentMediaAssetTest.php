<?php

use AIArmada\CommerceSupport\Models\Role;
use App\Models\Person;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

it('loads the shared Filament file upload component asset', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('contributions.submit-person'))
        ->assertOk()
        ->assertSee('/js/filament/forms/components/file-upload.js', false)
        ->assertSee('/js/aiarmada/commerce-support/file-upload-tabs.js', false);

    expect(is_file(public_path('js/filament/forms/components/file-upload.js')))->toBeTrue();
});

it('loads the shared FileUpload lifecycle workaround in the admin panel', function (): void {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
    ]);
    $user = User::factory()->create();
    $user->assignRole($role);
    $person = Person::factory()->create();

    $this->actingAs($user)
        ->get(route('filament.admin.resources.persons.edit', $person))
        ->assertOk()
        ->assertSee('/js/aiarmada/commerce-support/file-upload-tabs.js', false);

    expect(is_file(public_path('js/aiarmada/commerce-support/file-upload-tabs.js')))->toBeTrue();
});
