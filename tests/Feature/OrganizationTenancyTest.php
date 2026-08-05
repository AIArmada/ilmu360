<?php

declare(strict_types=1);

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Actions\CreateOrganizationAction;
use AIArmada\Organizations\Actions\MakeOrganizationPublicAction;
use AIArmada\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates a private organization through the authenticated API', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.client.organizations.store'), [
        'name' => 'Ilmu Circle',
        'description' => 'A private workspace.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Ilmu Circle')
        ->assertJsonPath('data.visibility', 'private')
        ->assertJsonPath('data.status', 'active');

    $organization = Organization::query()->where('name', 'Ilmu Circle')->firstOrFail();

    expect($organization->members()->whereKey($user->getKey())->first()?->pivot?->role)
        ->toBe(MemberRole::Owner->spatieRoleName());
});

it('only exposes public organizations from the public directory', function (): void {
    $owner = User::factory()->create();
    $private = CreateOrganizationAction::make()->handle($owner, ['name' => 'Private Circle']);
    $public = CreateOrganizationAction::make()->handle($owner, ['name' => 'Public Circle']);
    MakeOrganizationPublicAction::make()->handle($public, $owner);

    $response = $this->getJson(route('api.client.organizations.index'));

    $response->assertOk()
        ->assertJsonPath('data.0.name', 'Public Circle');

    expect(collect($response->json('data'))->pluck('name'))->not->toContain($private->name);
});

it('establishes the selected organization context for workspace requests', function (): void {
    $owner = User::factory()->create();
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Workspace Circle']);
    Sanctum::actingAs($owner);

    $response = $this->getJson(route('api.client.organizations.workspace', [
        'organization_id' => $organization->getKey(),
    ]));

    $response->assertOk()
        ->assertJsonPath('data.selected_organization.id', $organization->getKey())
        ->assertJsonPath('data.members.0.role', MemberRole::Owner->spatieRoleName());
});

it('keeps organization membership isolated between users', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $organization = CreateOrganizationAction::make()->handle($owner, ['name' => 'Isolated Circle']);
    AddMemberAction::make()->handle($organization, $otherUser, MemberRole::Viewer);

    Sanctum::actingAs($otherUser);

    $response = $this->getJson(route('api.client.organizations.workspace', [
        'organization_id' => $organization->getKey(),
    ]));

    $response->assertOk()
        ->assertJsonPath('data.selected_organization.id', $organization->getKey());
});

it('rejects a workspace request without organization context', function (): void {
    $owner = User::factory()->create();
    CreateOrganizationAction::make()->handle($owner, ['name' => 'Context Required Circle']);
    Sanctum::actingAs($owner);

    $this->getJson(route('api.client.organizations.workspace'))
        ->assertForbidden();
});
