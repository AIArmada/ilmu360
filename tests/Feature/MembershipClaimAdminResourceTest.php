<?php

use App\Enums\MembershipClaimStatus;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use App\Filament\Resources\MembershipClaims\Pages\ListMembershipClaims;
use App\Filament\Resources\MembershipClaims\Pages\ViewMembershipClaim;
use App\Models\Institution;
use App\Models\MembershipClaim;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('allows moderators to approve pending membership claims from the admin index', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();
    $claimant = User::factory()->create();
    $claim = MembershipClaim::factory()
        ->forInstitution($institution)
        ->create([
            'claimant_id' => $claimant->getKey(),
            'status' => MembershipClaimStatus::Pending,
        ]);

    Livewire::actingAs($moderator)
        ->test(ListMembershipClaims::class)
        ->assertCanSeeTableRecords([$claim])
        ->callTableAction('approve', $claim->getKey(), data: [
            'granted_role_slug' => 'admin',
            'reviewer_note' => 'Approved as admin.',
        ])
        ->assertHasNoTableActionErrors();

    expect($claim->fresh()->status)->toBe(MembershipClaimStatus::Approved)
        ->and($claim->fresh()->granted_role_slug)->toBe('admin')
        ->and($claim->fresh()->reviewer_id)->toBe($moderator->getKey())
        ->and($institution->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Institution))->toBe(['admin']);
});

it('allows moderators to approve membership claims as owner from the admin view page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $speaker = Speaker::factory()->create();
    $claimant = User::factory()->create();
    $claim = MembershipClaim::factory()
        ->forSpeaker($speaker)
        ->create([
            'claimant_id' => $claimant->getKey(),
            'status' => MembershipClaimStatus::Pending,
        ]);

    Livewire::actingAs($moderator)
        ->test(ViewMembershipClaim::class, ['record' => $claim->getKey()])
        ->callAction('approve', [
            'granted_role_slug' => 'owner',
            'reviewer_note' => 'Approved as owner.',
        ])
        ->assertHasNoErrors();

    expect($claim->fresh()->status)->toBe(MembershipClaimStatus::Approved)
        ->and($claim->fresh()->granted_role_slug)->toBe('owner')
        ->and($speaker->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Speaker))->toBe(['owner']);
});

it('allows moderators to reject pending membership claims from the admin view page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();
    $claim = MembershipClaim::factory()
        ->forInstitution($institution)
        ->create([
            'status' => MembershipClaimStatus::Pending,
        ]);

    Livewire::actingAs($moderator)
        ->test(ViewMembershipClaim::class, ['record' => $claim->getKey()])
        ->callAction('reject', [
            'reviewer_note' => 'Need stronger evidence.',
        ])
        ->assertHasNoErrors();

    expect($claim->fresh()->status)->toBe(MembershipClaimStatus::Rejected)
        ->and($claim->fresh()->reviewer_id)->toBe($moderator->getKey())
        ->and($claim->fresh()->reviewer_note)->toBe('Need stronger evidence.');
});

it('shows membership claim subjects on the admin index and links to the view page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create([
        'name' => 'Institusi Untuk Tuntutan',
    ]);
    $claim = MembershipClaim::factory()
        ->forInstitution($institution)
        ->create();

    $this->actingAs($administrator)
        ->get(MembershipClaimResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Institusi Untuk Tuntutan')
        ->assertSee(MembershipClaimResource::getUrl('view', ['record' => $claim]), false);
});
