<?php

use App\Actions\Membership\ApproveMembershipClaimAction;
use App\Actions\Membership\CancelMembershipClaimAction;
use App\Actions\Membership\RejectMembershipClaimAction;
use App\Actions\Membership\SubmitMembershipClaimAction;
use App\Enums\MembershipClaimStatus;
use App\Enums\MemberSubjectType;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\MembershipClaim;
use App\Models\Speaker;
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

    $claim = app(SubmitMembershipClaimAction::class)->handle(
        $institution,
        $claimant,
        'I help manage this institution.',
    );

    expect($claim)->toBeInstanceOf(MembershipClaim::class)
        ->and($claim->subject_type)->toBe(MemberSubjectType::Institution)
        ->and($claim->subject_id)->toBe($institution->getKey())
        ->and($claim->claimant_id)->toBe($claimant->getKey())
        ->and($claim->status)->toBe(MembershipClaimStatus::Pending);
});

it('rejects duplicate pending claims for the same subject and claimant', function () {
    $speaker = Speaker::factory()->create(['status' => 'verified', 'is_active' => true]);
    $claimant = User::factory()->create();

    MembershipClaim::factory()
        ->forSpeaker($speaker)
        ->create([
            'claimant_id' => $claimant->getKey(),
            'status' => MembershipClaimStatus::Pending,
        ]);

    expect(fn () => app(SubmitMembershipClaimAction::class)->handle(
        $speaker,
        $claimant,
        'I already submitted this once.',
    ))->toThrow(RuntimeException::class, 'membership_claim_duplicate_pending');
});

it('rejects claims when a pending invitation already exists', function () {
    $institution = Institution::factory()->create();
    $claimant = User::factory()->create([
        'email' => 'claimant@example.com',
    ]);

    MemberInvitation::create([
        'subject_type' => MemberSubjectType::Institution,
        'subject_id' => $institution->getKey(),
        'email' => 'claimant@example.com',
        'role_slug' => 'editor',
        'token' => 'pending-invitation-token',
        'invited_by' => User::factory()->create()->getKey(),
    ]);

    expect(fn () => app(SubmitMembershipClaimAction::class)->handle(
        $institution,
        $claimant,
        'I should use the invite instead.',
    ))->toThrow(RuntimeException::class, 'membership_claim_pending_invitation');
});

it('rejects claims when the user is already a member', function () {
    $speaker = Speaker::factory()->create();
    $claimant = User::factory()->create();
    $speaker->members()->attach($claimant);

    expect(fn () => app(SubmitMembershipClaimAction::class)->handle(
        $speaker,
        $claimant,
        'I am already attached.',
    ))->toThrow(RuntimeException::class, 'membership_claim_already_member');
});

it('approves a claim and grants editor membership', function () {
    $institution = Institution::factory()->create();
    $claimant = User::factory()->create();
    $reviewer = User::factory()->create();

    $claim = MembershipClaim::factory()
        ->forInstitution($institution)
        ->create([
            'claimant_id' => $claimant->getKey(),
            'status' => MembershipClaimStatus::Pending,
        ]);

    app(ApproveMembershipClaimAction::class)->handle($claim, $reviewer, 'editor', 'Approved as editor.');

    expect($claim->fresh()->status)->toBe(MembershipClaimStatus::Approved)
        ->and($claim->fresh()->reviewer_id)->toBe($reviewer->getKey())
        ->and($claim->fresh()->granted_role_slug)->toBe('editor')
        ->and($institution->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Institution))->toBe(['editor']);
});

it('approves a claim and can grant owner through the central moderation path', function () {
    $speaker = Speaker::factory()->create();
    $claimant = User::factory()->create();
    $reviewer = User::factory()->create();

    $claim = MembershipClaim::factory()
        ->forSpeaker($speaker)
        ->create([
            'claimant_id' => $claimant->getKey(),
            'status' => MembershipClaimStatus::Pending,
        ]);

    app(ApproveMembershipClaimAction::class)->handle($claim, $reviewer, 'owner', 'Approved as owner.');

    expect($claim->fresh()->status)->toBe(MembershipClaimStatus::Approved)
        ->and($claim->fresh()->granted_role_slug)->toBe('owner')
        ->and($speaker->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeTrue()
        ->and(app(MemberRoleCatalog::class)->roleNamesFor($claimant->fresh(), MemberSubjectType::Speaker))->toBe(['owner']);
});

it('rejects a claim and records reviewer metadata', function () {
    $institution = Institution::factory()->create();
    $claim = MembershipClaim::factory()
        ->forInstitution($institution)
        ->create([
            'status' => MembershipClaimStatus::Pending,
        ]);
    $reviewer = User::factory()->create();

    app(RejectMembershipClaimAction::class)->handle($claim, $reviewer, 'Not enough proof.');

    expect($claim->fresh()->status)->toBe(MembershipClaimStatus::Rejected)
        ->and($claim->fresh()->reviewer_id)->toBe($reviewer->getKey())
        ->and($claim->fresh()->reviewer_note)->toBe('Not enough proof.');
});

it('allows claimants to cancel their own pending claims', function () {
    $institution = Institution::factory()->create();
    $claimant = User::factory()->create();

    $claim = MembershipClaim::factory()
        ->forInstitution($institution)
        ->create([
            'claimant_id' => $claimant->getKey(),
            'status' => MembershipClaimStatus::Pending,
        ]);

    app(CancelMembershipClaimAction::class)->handle($claim, $claimant);

    expect($claim->fresh()->status)->toBe(MembershipClaimStatus::Cancelled)
        ->and($claim->fresh()->cancelled_at)->not->toBeNull();
});
