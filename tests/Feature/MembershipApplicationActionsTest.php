<?php

use AIArmada\Membership\Actions\ApproveMembershipApplicationAction;
use AIArmada\Membership\Actions\CancelMembershipApplicationAction;
use AIArmada\Membership\Actions\RejectMembershipApplicationAction;
use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\Api\SubmitMembershipApplicationInput;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\Membership\Models\MembershipApplication;
use App\Features\Membership\Services\MemberRoleCatalog;
use App\Features\Persons\Models\Person;
use App\Models\User;
use App\Shared\Membership\MembershipSubjectType;
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

    $claim = submitMembershipApplicationClaim(
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

it('rejects a claim when the applicant is already a member', function () {
    $person = Person::factory()->create();
    $claimant = User::factory()->create();

    addTestMember($person, $claimant, MemberRole::Viewer);

    expect(fn () => submitMembershipApplicationClaim(
        $person,
        $claimant,
        'I manage this speaker profile.',
    ))
        ->toThrow(RuntimeException::class, 'membership_application_already_member')
        ->and(MembershipApplication::query()
            ->where('subject_id', $person->getKey())
            ->where('applicant_id', $claimant->getKey())->exists())->toBeFalse();
});

it('rejects a second pending claim for the same subject and applicant', function () {
    $person = Person::factory()->create();
    $claimant = User::factory()->create();

    withGlobalOwnerContext(function () use ($person, $claimant): void {
        MembershipApplication::factory()
            ->for($person, 'subject')
            ->create([
                'applicant_id' => $claimant->getKey(),
                'status' => ApplicationStatus::Pending,
            ]);
    });

    expect(fn () => submitMembershipApplicationClaim(
        $person,
        $claimant,
        'I manage this speaker profile.',
    ))
        ->toThrow(RuntimeException::class, 'membership_application_duplicate_pending')
        ->and(MembershipApplication::query()
            ->where('subject_id', $person->getKey())
            ->where('applicant_id', $claimant->getKey())->count())->toBe(1);
});

it('rejects a claim while a matching invitation is still pending', function () {
    $institution = Institution::factory()->create();
    $claimant = User::factory()->create(['email' => 'invitee@example.com']);
    $inviter = User::factory()->create();

    $invitation = new MemberInvitation;
    $invitation->fill([
        'subject_type' => MemberSubjectType::Institution,
        'subject_id' => $institution->getKey(),
        'email' => $claimant->email,
        'role' => MemberRole::Editor->spatieRoleName(),
        'invited_by' => $inviter->getKey(),
    ]);
    $invitation->issue('pending-invitation-token', now()->addDay())->save();

    expect(fn () => submitMembershipApplicationClaim(
        $institution,
        $claimant,
        'I help manage this institution.',
    ))
        ->toThrow(RuntimeException::class, 'membership_application_pending_invitation')
        ->and(MembershipApplication::query()
            ->where('subject_id', $institution->getKey())
            ->where('applicant_id', $claimant->getKey())->exists())->toBeFalse();
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

it('rejects a role that is not offered for application approval', function () {
    $institution = Institution::factory()->create();
    $claimant = User::factory()->create();
    $reviewer = User::factory()->create();
    $application = MembershipApplication::factory()
        ->for($institution, 'subject')
        ->create([
            'applicant_id' => $claimant->getKey(),
            'status' => ApplicationStatus::Pending,
        ]);

    expect(fn () => app(MembershipApplications::class)->approve(
        (string) $application->getKey(),
        (string) $reviewer->getKey(),
        'viewer',
    ))->toThrow(InvalidArgumentException::class, 'Unsupported membership application approval role.')
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Pending)
        ->and($institution->fresh()->members()->whereKey($claimant->getKey())->exists())->toBeFalse();
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

function submitMembershipApplicationClaim(
    Institution|Person $subject,
    User $claimant,
    string $justification,
): MembershipApplication {
    $detail = app(MembershipApplications::class)->submit(new SubmitMembershipApplicationInput(
        subjectType: MembershipSubjectType::from($subject->getMorphClass()),
        subjectId: (string) $subject->getKey(),
        accountId: (string) $claimant->getKey(),
        justification: $justification,
    ));

    return MembershipApplication::query()->findOrFail($detail->application->id);
}
