<?php

declare(strict_types=1);

use AIArmada\Authz\Models\Role;
use App\Features\Persons\Models\Person;
use App\Models\User;
use App\Platform\Api\Admin\AdminResourceService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A user may have permission to edit a record without permission to read its
 * full details. The admin API must never substitute any mutation ability for view.
 */
final class DeniedAdminPersonReadPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return true;
    }
}

it('does not expose admin details, relations or media when editing is allowed but viewing is denied', function (): void {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::query()->where('name', 'viewer')->where('guard_name', 'web')->exists()) {
        $role = new Role;
        $role->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'viewer',
            'guard_name' => 'web',
        ])->save();
    }

    $viewer = User::factory()->create();
    $viewer->assignRole('viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $person = Person::factory()->create([
        'name' => 'Read-restricted Person',
    ]);
    $routeKey = (string) $person->getRouteKey();

    Gate::policy(Person::class, DeniedAdminPersonReadPolicy::class);

    expect($viewer->hasApplicationAdminAccess())->toBeTrue()
        ->and($viewer->can('viewAny', Person::class))->toBeTrue()
        ->and($viewer->can('update', $person))->toBeTrue()
        ->and($viewer->can('view', $person))->toBeFalse();

    Sanctum::actingAs($viewer);

    $this->getJson('/api/v1/admin/people/'.$routeKey)->assertForbidden();
    $this->getJson('/api/v1/admin/people/'.$routeKey.'/relations/institutions')->assertForbidden();

    expect(fn () => app(AdminResourceService::class)->recordMedia('people', $routeKey))
        ->toThrow(HttpException::class);
});
