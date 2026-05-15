<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('includes the curated documentation catalog in the admin api manifest', function (): void {
    $admin = documentationAdminManifestUser();

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/manifest')
        ->assertOk();

    $documentationLibrary = collect($response->json('data.docs.library') ?? []);

    expect($response->json('data.docs.catalog_endpoint'))
        ->toContain('/api/v1/documentation')
        ->and($response->json('data.docs.document_endpoint_template'))
        ->toContain('/api/v1/documentation/documentId')
        ->and($documentationLibrary->pluck('id')->all())
        ->toContain('docs-admin-mcp-guide', 'docs-general-mcp-guide', 'docs-technical-documentation');
});

function documentationAdminManifestUser(string $role = 'super_admin'): User
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
        $roleRecord = new Role;
        $roleRecord->forceFill([
            'id' => (string) Str::uuid(),
            'name' => $role,
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}