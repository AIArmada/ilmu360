<?php

declare(strict_types=1);

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Contracts\CurrentOrganizationResolver as CurrentOrganizationResolverContract;
use AIArmada\Organizations\Models\Organization;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationCommands;
use App\Features\Organizations\Api\OrganizationWorkspace;
use App\Features\Organizations\EntryPoints\Http\OrganizationController;
use App\Features\Organizations\Services\CurrentOrganizationResolver;
use App\Features\Organizations\Services\OrganizationAccessService;
use App\Features\Organizations\Services\OrganizationCommandsService;
use App\Features\Organizations\Services\OrganizationWorkspaceService;
use App\Livewire\Pages\Dashboard\Organizations\CreateEvent as CreateOrganizationEvent;
use App\Livewire\Pages\Dashboard\Organizations\CreateOrganization;
use App\Livewire\Pages\Dashboard\Organizations\Index as OrganizationsIndex;
use App\Livewire\Pages\Dashboard\Organizations\Workspace as OrganizationWorkspacePage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('binds the organization seams to their feature adapters', function (): void {
    expect(app(OrganizationAccess::class))->toBeInstanceOf(OrganizationAccessService::class)
        ->and(app(OrganizationWorkspace::class))->toBeInstanceOf(OrganizationWorkspaceService::class)
        ->and(app(OrganizationCommands::class))->toBeInstanceOf(OrganizationCommandsService::class)
        ->and(config('organizations.resolver'))->toBe(CurrentOrganizationResolver::class)
        ->and(app(CurrentOrganizationResolverContract::class))->toBeInstanceOf(CurrentOrganizationResolver::class);
});

it('registers organization transport and route names inside the feature', function (): void {
    $store = Route::getRoutes()->getByName('api.client.organizations.store');
    $workspace = Route::getRoutes()->getByName('api.client.organizations.workspace');

    expect($store?->getActionName())->toBe(OrganizationController::class.'@store')
        ->and($store?->middleware())->toBe(['api', 'auth:sanctum'])
        ->and($workspace?->getActionName())->toBe(OrganizationController::class.'@workspace')
        ->and($workspace?->middleware())->toBe([
            'api',
            'auth:sanctum',
            'current.organization:true',
        ])
        ->and(Route::getRoutes()->getByName('api.client.organizations.index')?->getActionName())
        ->toBe(OrganizationController::class.'@index')
        ->and(Route::getRoutes()->getByName('api.client.organizations.show')?->getActionName())
        ->toBe(OrganizationController::class.'@show');

    $webRoutes = [
        'dashboard.organizations.index' => ['/organisasi', 'pages.dashboard.organizations.index'],
        'dashboard.organizations.create' => ['/organisasi/cipta', 'pages.dashboard.organizations.create-organization'],
        'dashboard.organizations.show' => ['/organisasi/{organization}', 'pages.dashboard.organizations.workspace'],
        'dashboard.organizations.events.create' => ['/organisasi/{organization}/majlis/cipta', 'pages.dashboard.organizations.create-event'],
    ];

    foreach ($webRoutes as $name => [$uri, $component]) {
        $route = Route::getRoutes()->getByName($name);

        expect($route?->uri())->toBe(ltrim($uri, '/'))
            ->and($route?->getAction('livewire_component'))->toBe($component)
            ->and($route?->middleware())->toBe(['web', 'auth']);
    }

    $finder = app('livewire.finder');

    expect($finder->resolveClassComponentClassName('pages.dashboard.organizations.index'))->toBe(OrganizationsIndex::class)
        ->and($finder->resolveClassComponentClassName('pages.dashboard.organizations.create-organization'))->toBe(CreateOrganization::class)
        ->and($finder->resolveClassComponentClassName('pages.dashboard.organizations.workspace'))->toBe(OrganizationWorkspacePage::class)
        ->and($finder->resolveClassComponentClassName('pages.dashboard.organizations.create-event'))->toBe(CreateOrganizationEvent::class);
});

it('keeps organization-only members outside ahli and member MCP access', function (): void {
    $member = User::factory()->create();

    app(OrganizationCommands::class)->create((string) $member->getKey(), [
        'name' => 'Panel Hold Organization',
    ]);

    expect($member->hasAhliPanelAccess())->toBeFalse()
        ->and($member->hasMemberMcpAccess())->toBeFalse()
        ->and($member->hasAnyMcpAccess())->toBeFalse();
});

it('creates, reads and transitions organizations through the seam boundaries', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $commands = app(OrganizationCommands::class);
    $access = app(OrganizationAccess::class);
    $workspace = app(OrganizationWorkspace::class);

    $ownerId = (string) $owner->getKey();
    $summary = $commands->create($ownerId, [
        'name' => 'Seam Organization',
        'description' => 'Created through the command boundary.',
    ]);

    $found = $access->findForAccount($ownerId, $summary->id);
    $detail = $workspace->detailFor($ownerId, $summary->id);

    expect($summary->name)->toBe('Seam Organization')
        ->and($summary->visibility)->toBe('private')
        ->and($summary->status)->toBe('active')
        ->and($summary->memberCount)->toBe(1)
        ->and($found?->id)->toBe($summary->id)
        ->and($access->findForAccount((string) $outsider->getKey(), $summary->id))->toBeNull()
        ->and($access->accessibleFor($ownerId))->toHaveCount(1)
        ->and($access->can($ownerId, $summary->id, 'organization.view'))->toBeTrue()
        ->and($access->can($ownerId, $summary->id, 'organization.transfer-ownership'))->toBeTrue()
        ->and($access->can((string) $outsider->getKey(), $summary->id, 'organization.view'))->toBeFalse()
        ->and($access->canCreateEvent($ownerId, $summary->id))->toBeTrue()
        ->and($access->canCreateEvent((string) $outsider->getKey(), $summary->id))->toBeFalse()
        ->and($detail?->members)->toHaveCount(1)
        ->and($detail?->members[0]->accountId)->toBe($ownerId)
        ->and($detail?->members[0]->role)->toBe(MemberRole::Owner->spatieRoleName())
        ->and($workspace->organizationsFor($ownerId))->toHaveCount(1);

    $public = $commands->makePublic($summary->id, $ownerId);

    expect($public->visibility)->toBe('public');

    $private = $commands->makePrivate($summary->id, $ownerId);

    expect($private->visibility)->toBe('private')
        ->and(fn () => $access->authorizeCreateEvent((string) $outsider->getKey(), $summary->id))
        ->toThrow(AuthorizationException::class, 'The actor cannot create events for this organization.');

    $commands->suspend($summary->id, $ownerId);

    expect($access->canCreateEvent($ownerId, $summary->id))->toBeFalse();
});

it('resolves organization context per request without retaining the previous request', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $commands = app(OrganizationCommands::class);

    $firstOrganization = $commands->create((string) $firstUser->getKey(), ['name' => 'First Request Organization']);
    $secondOrganization = $commands->create((string) $secondUser->getKey(), ['name' => 'Second Request Organization']);

    $resolver = app(CurrentOrganizationResolverContract::class);

    expect($resolver)->toBe(app(CurrentOrganizationResolverContract::class));

    $this->actingAs($firstUser);
    app()->instance('request', Request::create('/api/organization-workspace', 'GET', [
        'organization_id' => $firstOrganization->id,
    ]));

    expect($resolver->resolve()?->getKey())->toBe($firstOrganization->id);

    $this->actingAs($secondUser);
    app()->instance('request', Request::create('/api/organization-workspace', 'GET', [
        'organization_id' => $secondOrganization->id,
    ]));

    expect($resolver->resolve()?->getKey())->toBe($secondOrganization->id);

    $this->actingAs($firstUser);
    app()->instance('request', Request::create('/api/organization-workspace', 'GET', [
        'organization_id' => $secondOrganization->id,
    ]));

    expect($resolver->resolve())->toBeNull();
});

it('transfers organization ownership through the command boundary', function (): void {
    $owner = User::factory()->create();
    $newOwner = User::factory()->create();
    $commands = app(OrganizationCommands::class);
    $workspace = app(OrganizationWorkspace::class);

    $ownerId = (string) $owner->getKey();
    $newOwnerId = (string) $newOwner->getKey();
    $summary = $commands->create($ownerId, ['name' => 'Transfer Organization']);

    AddMemberAction::make()->handle(
        Organization::query()->findOrFail($summary->id),
        $newOwner,
        MemberRole::Editor,
    );

    $commands->transferOwnership($summary->id, $ownerId, $newOwnerId);

    $roles = collect($workspace->membersFor($summary->id))
        ->mapWithKeys(static fn ($member): array => [$member->accountId => $member->role]);

    expect($roles->get($newOwnerId))->toBe(MemberRole::Owner->spatieRoleName())
        ->and($roles->get($ownerId))->toBe(MemberRole::Admin->spatieRoleName());
});
