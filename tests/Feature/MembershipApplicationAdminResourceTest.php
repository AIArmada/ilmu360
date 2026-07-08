<?php

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Resources\MembershipApplications\Pages\ListMembershipApplications;
use App\Filament\Resources\MembershipApplications\Pages\ViewMembershipApplication;
use App\Models\Institution;
use App\Models\MembershipApplication;
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

it('allows moderators to approve pending membership applications from the admin index', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();
    $claimant = User::factory()->create();
    $claim = MembershipApplication::factory()
        ->forInstitution($institution)
        ->create([
            'applicant_id' => $claimant->getKey(),
            'status' => ApplicationStatus::Pending,
        ]);

    Livewire::actingAs($moderator)
        ->test(ListMembershipApplications::class)
        ->assertCanSeeTableRecords([$claim])
        ->callTableAction('approve', $claim->getKey(), data: [
            'granted_role' => 'admin',
            'reviewer_note' => 'Approved as admin.',
        ])
        ->assertHasNoTableActionErrors();

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($claim->fresh()->granted_role)->toBe('admin')
        ->and($claim->fresh()->reviewer_id)->toBe($moderator->getKey())
        ->and($institution->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Institution))->toBe(['admin']);
});

it('allows moderators to approve membership applications as owner from the admin view page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $speaker = Speaker::factory()->create();
    $claimant = User::factory()->create();
    $claim = MembershipApplication::factory()
        ->forSpeaker($speaker)
        ->create([
            'applicant_id' => $claimant->getKey(),
            'status' => ApplicationStatus::Pending,
        ]);

    Livewire::actingAs($moderator)
        ->test(ViewMembershipApplication::class, ['record' => $claim->getKey()])
        ->callAction('approve', [
            'granted_role' => 'owner',
            'reviewer_note' => 'Approved as owner.',
        ])
        ->assertHasNoErrors();

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($claim->fresh()->granted_role)->toBe('owner')
        ->and($speaker->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Speaker))->toBe(['owner']);
});

it('allows moderators to reject pending membership applications from the admin view page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()
        ->forInstitution($institution)
        ->create([
            'status' => ApplicationStatus::Pending,
        ]);

    Livewire::actingAs($moderator)
        ->test(ViewMembershipApplication::class, ['record' => $claim->getKey()])
        ->callAction('reject', [
            'reviewer_note' => 'Need stronger evidence.',
        ])
        ->assertHasNoErrors();

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($claim->fresh()->reviewer_id)->toBe($moderator->getKey())
        ->and($claim->fresh()->reviewer_note)->toBe('Need stronger evidence.');
});

it('shows membership application subjects on the admin index and links to the view page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create([
        'name' => 'Institusi Untuk Tuntutan',
    ]);
    $claim = MembershipApplication::factory()
        ->forInstitution($institution)
        ->create();

    $this->actingAs($administrator)
        ->get(MembershipApplicationResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Institusi Untuk Tuntutan')
        ->assertSee(MembershipApplicationResource::getUrl('view', ['record' => $claim]), false);
});
