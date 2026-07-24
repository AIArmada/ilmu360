<?php

use AIArmada\Membership\Actions\ApproveMembershipApplicationAction;
use AIArmada\Membership\Actions\CancelMembershipApplicationAction;
use AIArmada\Membership\Actions\RejectMembershipApplicationAction;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Membership\SubmitMembershipApplicationAction;
use App\Enums\MemberSubjectType;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\Person;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('submits a pending institution membership claim', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $claimant = User::factory()->create();

    $claim = app(SubmitMembershipApplicationAction::class)->handle(
        $institution,
        $claimant,
        'I help manage this institution.',
    );

    expect($claim)->toBeInstanceOf(MembershipApplication::class)
        ->and($claim->subject_type)->toBe(MemberSubjectType::Institution)
        ->and($claim->subject_id)->toBe($institution->getKey())
        ->and($claim->applicant_id)->toBe($claimant->getKey())
        ->and($claim->status)->toBe(ApplicationStatus::Pending);
});

it('approves a claim and grants editor membership', function () {
    $institution = Institution::factory()->create();
    $claimant = User::factory()->create();
    $reviewer = User::factory()->create();

    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'applicant_id' => $claimant->getKey(),
            'status' => ApplicationStatus::Pending,
        ]);

    app(ApproveMembershipApplicationAction::class)->handle($claim, $reviewer, MemberRole::Editor, 'Approved as editor.');

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($claim->fresh()->reviewer_id)->toBe($reviewer->getKey())
        ->and($claim->fresh()->granted_role)->toBe('editor')
        ->and($institution->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Institution))->toBe(['editor']);
});

it('approves a claim and can grant owner through the central moderation path', function () {
    $person = Person::factory()->create();
    $claimant = User::factory()->create();
    $reviewer = User::factory()->create();

    $claim = MembershipApplication::factory()
        ->for($person, 'subject')
        ->create([
            'applicant_id' => $claimant->getKey(),
            'status' => ApplicationStatus::Pending,
        ]);

    app(ApproveMembershipApplicationAction::class)->handle($claim, $reviewer, MemberRole::Owner, 'Approved as owner.');

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($claim->fresh()->granted_role)->toBe('owner')
        ->and($person->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Person))->toBe(['owner']);
});

it('rejects a claim and records reviewer metadata', function () {
    $institution = Institution::factory()->create();
    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'status' => ApplicationStatus::Pending,
        ]);
    $reviewer = User::factory()->create();

    app(RejectMembershipApplicationAction::class)->handle($claim, $reviewer, 'Not enough proof.');

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($claim->fresh()->reviewer_id)->toBe($reviewer->getKey())
        ->and($claim->fresh()->reviewer_note)->toBe('Not enough proof.');
});

it('allows claimants to cancel their own pending claims', function () {
    $institution = Institution::factory()->create();
    $claimant = User::factory()->create();

    $claim = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'applicant_id' => $claimant->getKey(),
            'status' => ApplicationStatus::Pending,
        ]);

    app(CancelMembershipApplicationAction::class)->handle($claim);

    expect($claim->fresh()->status)->toBe(ApplicationStatus::Cancelled)
        ->and($claim->fresh()->cancelled_at)->not->toBeNull();
});
