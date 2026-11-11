<?php

declare(strict_types=1);

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Models\Organization;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationCommands;
use App\Features\Organizations\Api\OrganizationWorkspace;
use App\Models\User;
use App\Support\Organizations\OrganizationAccessService;
use App\Support\Organizations\OrganizationCommandsService;
use App\Support\Organizations\OrganizationWorkspaceService;

it('binds the organization seams to their interim support adapters', function (): void {
    expect(app(OrganizationAccess::class))->toBeInstanceOf(OrganizationAccessService::class)
        ->and(app(OrganizationWorkspace::class))->toBeInstanceOf(OrganizationWorkspaceService::class)
        ->and(app(OrganizationCommands::class))->toBeInstanceOf(OrganizationCommandsService::class);
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
        ->and($detail?->members)->toHaveCount(1)
        ->and($detail?->members[0]->accountId)->toBe($ownerId)
        ->and($detail?->members[0]->role)->toBe(MemberRole::Owner->spatieRoleName())
        ->and($workspace->organizationsFor($ownerId))->toHaveCount(1);

    $public = $commands->makePublic($summary->id, $ownerId);

    expect($public->visibility)->toBe('public');

    $private = $commands->makePrivate($summary->id, $ownerId);

    expect($private->visibility)->toBe('private');
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
