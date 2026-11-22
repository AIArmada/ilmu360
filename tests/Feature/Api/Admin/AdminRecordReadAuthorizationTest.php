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
use Tests\Support\DeniedAdminPersonReadPolicy;

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

    try {
        app(AdminResourceService::class)->recordMedia('people', $routeKey);

        throw new LogicException('Admin media reads must require view permission.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});
