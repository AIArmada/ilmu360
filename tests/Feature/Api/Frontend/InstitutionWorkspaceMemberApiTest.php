<?php

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('membership.pivot.table_suffix', '_members');
});

it('updates a member role in an institution workspace', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    addTestMember($institution, $admin, 'admin');
    addTestMember($institution, $member, 'viewer');

    Sanctum::actingAs($admin);

    $this->putJson(route('api.client.institution-workspace.members.update', [
        'institutionId' => $institution->getKey(),
        'memberId' => $member->getKey(),
    ]), [
        'role_id' => 'editor',
    ])->assertOk()
        ->assertJsonPath('data.member.id', $member->getKey())
        ->assertJsonPath('data.member.name', $member->name);

    expect($institution->members()->whereKey($member->getKey())->first()?->pivot->role)->toBe('editor');
});

it('prevents viewer from updating member roles', function () {
    $institution = Institution::factory()->create();
    $viewer = User::factory()->create();
    $member = User::factory()->create();

    addTestMember($institution, $viewer, 'viewer');
    addTestMember($institution, $member, 'viewer');

    Sanctum::actingAs($viewer);

    $this->putJson(route('api.client.institution-workspace.members.update', [
        'institutionId' => $institution->getKey(),
        'memberId' => $member->getKey(),
    ]), [
        'role_id' => 'editor',
    ])->assertForbidden();
});

it('prevents updating owner role', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create();
    $owner = User::factory()->create();

    addTestMember($institution, $admin, 'admin');
    addTestMember($institution, $owner, 'owner');

    Sanctum::actingAs($admin);

    $this->putJson(route('api.client.institution-workspace.members.update', [
        'institutionId' => $institution->getKey(),
        'memberId' => $owner->getKey(),
    ]), [
        'role_id' => 'editor',
    ])->assertUnprocessable();
});

it('rejects invalid role id when updating member role', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    addTestMember($institution, $admin, 'admin');
    addTestMember($institution, $member, 'viewer');

    Sanctum::actingAs($admin);

    $this->putJson(route('api.client.institution-workspace.members.update', [
        'institutionId' => $institution->getKey(),
        'memberId' => $member->getKey(),
    ]), [
        'role_id' => 'owner',
    ])->assertUnprocessable()->assertJsonValidationErrors(['role_id']);
});

it('removes a member from an institution workspace', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    addTestMember($institution, $admin, 'admin');
    addTestMember($institution, $member, 'viewer');

    Sanctum::actingAs($admin);

    $this->deleteJson(route('api.client.institution-workspace.members.destroy', [
        'institutionId' => $institution->getKey(),
        'memberId' => $member->getKey(),
    ]))->assertOk()
        ->assertJsonPath('data.removed_member_id', $member->getKey());

    expect($institution->members()->whereKey($member->getKey())->exists())->toBeFalse();
});

it('prevents viewer from removing a member', function () {
    $institution = Institution::factory()->create();
    $viewer = User::factory()->create();
    $member = User::factory()->create();

    addTestMember($institution, $viewer, 'viewer');
    addTestMember($institution, $member, 'viewer');

    Sanctum::actingAs($viewer);

    $this->deleteJson(route('api.client.institution-workspace.members.destroy', [
        'institutionId' => $institution->getKey(),
        'memberId' => $member->getKey(),
    ]))->assertForbidden();
});

it('prevents removing an owner', function () {
    $institution = Institution::factory()->create();
    $admin = User::factory()->create();
    $owner = User::factory()->create();

    addTestMember($institution, $admin, 'admin');
    addTestMember($institution, $owner, 'owner');

    Sanctum::actingAs($admin);

    $this->deleteJson(route('api.client.institution-workspace.members.destroy', [
        'institutionId' => $institution->getKey(),
        'memberId' => $owner->getKey(),
    ]))->assertUnprocessable();
});
