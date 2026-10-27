<?php

use AIArmada\Authz\Models\Role;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\ContributionRequestType;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\User;
use App\Policies\ContributionRequestPolicy;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('allows privileged reviewers to review create and update requests without a target entity', function (string $roleName, ContributionRequestType $requestType) {
    Role::findOrCreate($roleName, 'web');
    $reviewer = User::factory()->create();
    $reviewer->assignRole($roleName);
    $request = ContributionRequest::factory()->make([
        'type' => $requestType,
        'proposer_id' => $reviewer->getKey(),
    ])->setRelation('entity', null);

    expect(app(ContributionRequestPolicy::class)->review($reviewer, $request))->toBeTrue();
})->with(['super_admin', 'admin', 'moderator'])
    ->with([ContributionRequestType::Create, ContributionRequestType::Update]);

it('denies create request review to an entity owner who can update the entity', function () {
    $this->seed(PermissionSeeder::class);
    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    addTestMember($institution, $owner, MemberRole::Owner);
    $request = ContributionRequest::factory()->make([
        'type' => ContributionRequestType::Create,
        'proposer_id' => $owner->getKey(),
    ])->setRelation('entity', $institution);

    expect($owner->can('update', $institution))->toBeTrue();
    expect(app(ContributionRequestPolicy::class)->review($owner, $request))->toBeFalse();
});

it('denies update request review when its target entity is missing', function () {
    $reviewer = User::factory()->create();
    $request = ContributionRequest::factory()->make([
        'type' => ContributionRequestType::Update,
        'proposer_id' => $reviewer->getKey(),
    ])->setRelation('entity', null);

    expect(app(ContributionRequestPolicy::class)->review($reviewer, $request))->toBeFalse();
});
