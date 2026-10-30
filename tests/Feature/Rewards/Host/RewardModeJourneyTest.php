<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\AcknowledgeRewardRecognitionAction;
use AIArmada\Rewards\Actions\ApplyRewardSourceCorrectionsAction;
use AIArmada\Rewards\Actions\ApproveRewardPayoutAction;
use AIArmada\Rewards\Actions\ConfirmRewardPayoutAction;
use AIArmada\Rewards\Actions\CreateRewardBenefitItemAction;
use AIArmada\Rewards\Actions\CreateRewardProgramAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\FulfillRewardBenefitAction;
use AIArmada\Rewards\Actions\PublishRewardProgramVersionAction;
use AIArmada\Rewards\Actions\RecordRewardBenefitRecallAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\RequestRewardPayoutAction;
use AIArmada\Rewards\Actions\RevokeRewardBenefitGrantAction;
use AIArmada\Rewards\Actions\SettleRewardRecoveryAction;
use AIArmada\Rewards\Actions\SubmitRewardPayoutAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Actions\VerifyRewardPayeeAction;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBenefitGrantStatus;
use AIArmada\Rewards\Enums\RewardPayoutRequestStatus;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Enums\RewardRecoveryStatus;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBadgeGrant;
use AIArmada\Rewards\Models\RewardBenefitGrant;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardPointLot;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardRecovery;
use AIArmada\Rewards\Support\RewardPointLedger;
use AIArmada\Rewards\Support\RewardPublishAssembler;
use App\Models\EventCheckin;
use App\Models\ShareOutcomeCorrection;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Rewards\Host\Support\RewardHostFixtures;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

// R9 proof: the full setup -> enroll -> qualify -> hold/review -> fulfill ->
// correct/recover path per mode through the REAL host source path (receipt +
// conversion + production reader) and REAL distinct member/staff identities.
// No fake source facts, no placeholder actor UUIDs.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function journeyUsers(): array
{
    $member = User::factory()->create();
    $staff = User::factory()->create();
    $staff->assignRole('admin');
    $verifier = User::factory()->create();
    $verifier->assignRole('admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$member, $staff, $verifier];
}

/**
 * @return array<string, mixed>
 */
function journeyFormData(RewardProgramMode $mode): array
{
    $base = [
        'eligibility_outcomes' => ['signup'],
        'eligibility_categories' => '',
        'min_verification' => 'accepted',
        'first_only' => false,
        'exclude_self_credit' => true,
        'require_enrollment' => true,
        'require_claimed_identity' => true,
        'preclaim_lookback_days' => 30,
        'hold_days' => 0,
        'cap_scope_1' => 'recipient_source_component',
        'cap_limit_1' => 1,
        'ambiguous_device' => true,
        'manual_review_outcomes' => [],
        'terms_label' => 'Journey terms',
        'terms_version_label' => 'v1',
        'terms_body' => 'Earn only on verified eligible outcomes.',
        'terms_earn_basis' => 'Verified eligible outcomes only.',
        'terms_changes' => 'Changes apply prospectively after reacceptance.',
        'terms_no_ranking' => 'No public ranking.',
    ];

    return match ($mode) {
        RewardProgramMode::Recognition => [...$base, 'recognition_template' => 'quiet-thanks'],
        RewardProgramMode::Points => [...$base, 'points_amount' => 10, 'points_unit' => 'pts'],
        RewardProgramMode::Badges => [...$base, 'badge_key' => 'first-share'],
        RewardProgramMode::Cash => [...$base,
            'cash_minor_units' => 500,
            'cash_currency' => 'MYR',
            'eligibility_outcomes' => ['event_checkin'],
            'min_verification' => 'staff_verified',
        ],
        RewardProgramMode::Benefit => [...$base,
            'benefit_key' => 'ebook-credit',
            'benefit_conditions' => "Good standing\nOne redemption per award",
            'eligibility_outcomes' => ['event_checkin'],
            'min_verification' => 'staff_verified',
        ],
        RewardProgramMode::Combined => [...$base,
            'component_recognition' => true,
            'component_points' => true,
            'component_badge' => true,
            'component_cash' => false,
            'component_benefit' => false,
            'recognition_template' => 'quiet-thanks',
            'points_amount' => 10,
            'points_unit' => 'pts',
            'badge_key' => 'first-share',
        ],
    };
}

/**
 * @return array{0: RewardProgram, 1: mixed}
 */
function journeyProgram(string $prefix, RewardProgramMode $mode, string $staffId): array
{
    $program = CreateRewardProgramAction::run(
        $prefix, 'Journey '.$prefix, $mode, null, null, 'Journey scope.',
    );

    $payload = RewardPublishAssembler::assemble($mode, journeyFormData($mode));

    $asOf = CarbonImmutable::now('UTC')->subHour();

    $version = PublishRewardProgramVersionAction::run(
        $program,
        $asOf,
        $payload['rules'],
        $payload['terms'],
        'journey setup',
        'user',
        $staffId,
        $asOf,
    );

    if ($mode === RewardProgramMode::Cash) {
        RecordRewardFundingSourceAction::run(
            'TREASURY-'.$prefix, 'MYR', 10000, 'user', $staffId, 'staff',
            ['document' => 'treasury-'.$prefix], CarbonImmutable::now('UTC')->subHours(2),
        );
        RecordRewardFundingAction::run(
            $program, 'MYR', 10000, 'TREASURY-'.$prefix,
            'user', $staffId, 'staff', ['document' => 'treasury-'.$prefix],
            CarbonImmutable::now('UTC')->subHours(2),
        );
    }

    if ($mode === RewardProgramMode::Benefit) {
        CreateRewardBenefitItemAction::run(
            $program, 'ebook-credit', 'Ebook credit', ['Good standing'], null, null, true, 5,
            'user', $staffId, 'staff',
        );
    }

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    return [$program->refresh(), $version];
}

/**
 * Staff-verified checkin fact for money/benefit journeys: a real checkin
 * row verified by real staff, read through the production reader.
 *
 * @return array{0: RewardAward, 1: string}
 */
function journeyCheckinAward(RewardProgram $program, mixed $version, User $member, User $staff): array
{
    if (RewardEnrollment::query()->where('program_id', $program->getKey())->where('member_id', (string) $member->getKey())->doesntExist()) {
        EnrollRewardMemberAction::run($program, 'user', (string) $member->getKey(), $version, 'member-portal');
    }

    $actor = User::factory()->create();
    $checkin = EventCheckin::factory()->create([
        'verified_by_user_id' => (string) $staff->getKey(),
    ]);
    $key = ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey());
    RewardHostFixtures::receipt($key, 'checkin:'.$checkin->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'event_checkin',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    RewardHostFixtures::conversion($key, [
        'conversion_type' => 'event_checkin',
        'sharer_user_id' => (string) $member->getKey(),
        'actor_user_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    EvaluateRewardEntitlementJob::dispatchSync($key);

    $award = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('recipient_id', (string) $member->getKey())
        ->orderByDesc('id')
        ->firstOrFail();

    return [$award, $key];
}

/**
 * @return array{0: RewardAward, 1: string}
 */
function journeyAward(RewardProgram $program, mixed $version, User $member): array
{
    if (RewardEnrollment::query()->where('program_id', $program->getKey())->where('member_id', (string) $member->getKey())->doesntExist()) {
        EnrollRewardMemberAction::run($program, 'user', (string) $member->getKey(), $version, 'member-portal');
    }

    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'signup',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    RewardHostFixtures::conversion($key, [
        'sharer_user_id' => (string) $member->getKey(),
        'actor_user_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    EvaluateRewardEntitlementJob::dispatchSync($key);

    $award = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('recipient_id', (string) $member->getKey())
        ->orderByDesc('id')
        ->firstOrFail();

    return [$award, $key];
}

function journeyApprove(RewardAward $award, string $staffId): RewardAward
{
    // Clean facts auto-approve at evaluation; ambiguous ones land Held or
    // PendingReview for this staff decision. Both are the designed path.
    if ($award->status === RewardAwardStatus::Approved) {
        return $award;
    }

    return TransitionRewardAwardAction::run(
        $award, RewardAwardStatus::Approved, 'user', $staffId, 'staff', 'policy-ok',
    );
}

function journeyFulfill(RewardAward $award, string $staffId): RewardAward
{
    return TransitionRewardAwardAction::run(
        $award, RewardAwardStatus::Fulfilled, 'user', $staffId, 'staff', 'fulfill-ok',
    );
}

/**
 * @return array<string, mixed>
 */
function journeyCorrect(string $businessKey, string $staffId): array
{
    Queue::fake();

    ShareOutcomeCorrection::query()->create([
        'conversion_id' => (string) Str::uuid(),
        'business_key' => $businessKey,
        'reason' => 'event-withdrawn',
        'occurred_at' => CarbonImmutable::now('UTC'),
    ]);

    return ApplyRewardSourceCorrectionsAction::run(
        'user', $staffId, 'staff', CarbonImmutable::now('UTC'),
    );
}

it('journeys a recognition award from setup to fulfillment to reversal', function () {
    [$member, $staff] = journeyUsers();
    $staffId = (string) $staff->getKey();
    $memberId = (string) $member->getKey();
    [$program, $version] = journeyProgram('journey-recognition', RewardProgramMode::Recognition, $staffId);
    [$award, $key] = journeyAward($program, $version, $member);

    expect($award->status)->toBeIn([RewardAwardStatus::Held, RewardAwardStatus::PendingReview, RewardAwardStatus::Approved]);

    $approved = journeyApprove($award, $staffId);
    expect($approved->status)->toBe(RewardAwardStatus::Approved);

    $fulfilled = journeyFulfill($approved, $staffId);
    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);

    $acknowledged = AcknowledgeRewardRecognitionAction::run($fulfilled, $memberId);
    expect($acknowledged->acknowledged_at)->not->toBeNull();

    $report = journeyCorrect($key, $staffId);

    expect($report['reversed'])->toHaveCount(1)
        ->and($report['errors'])->toBe([])
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Reversed);
});

it('journeys a points award from setup to spendable lots to clawback', function () {
    [$member, $staff] = journeyUsers();
    $staffId = (string) $staff->getKey();
    $memberId = (string) $member->getKey();
    [$program, $version] = journeyProgram('journey-points', RewardProgramMode::Points, $staffId);
    [$award, $key] = journeyAward($program, $version, $member);

    $approved = journeyApprove($award, $staffId);
    $fulfilled = journeyFulfill($approved, $staffId);

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, (string) $program->family_key, 'pts', CarbonImmutable::now('UTC'));
    expect($buckets->available)->toBe(10)
        ->and(RewardPointLot::query()->where('recipient_id', $memberId)->count())->toBe(1);

    $report = journeyCorrect($key, $staffId);

    expect($report['reversed'])->toHaveCount(1)
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Reversed);

    $after = RewardPointLedger::balancesFor('user', $memberId, (string) $program->family_key, 'pts', CarbonImmutable::now('UTC'));
    expect($after->available)->toBe(0);
});

it('journeys a badge award from setup to grant to reversal', function () {
    [$member, $staff] = journeyUsers();
    $staffId = (string) $staff->getKey();
    $memberId = (string) $member->getKey();
    [$program, $version] = journeyProgram('journey-badges', RewardProgramMode::Badges, $staffId);
    [$award, $key] = journeyAward($program, $version, $member);

    $approved = journeyApprove($award, $staffId);
    $fulfilled = journeyFulfill($approved, $staffId);

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled)
        ->and(RewardBadgeGrant::query()
            ->where('recipient_id', $memberId)
            ->where('badge_key', 'first-share')
            ->count())->toBe(1);

    $report = journeyCorrect($key, $staffId);

    expect($report['reversed'])->toHaveCount(1)
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Reversed);
});

it('journeys a cash award from funding to paid fulfillment to recovery', function () {
    RewardLedgerFixtures::useManualPayouts();

    [$member, $staff, $verifier] = journeyUsers();
    $staffId = (string) $staff->getKey();
    $verifierId = (string) $verifier->getKey();
    $memberId = (string) $member->getKey();
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    [$program, $version] = journeyProgram('journey-cash', RewardProgramMode::Cash, $staffId);
    [$award, $key] = journeyCheckinAward($program, $version, $member, $staff);

    // Distinct real identities across every seat.
    expect(collect([$memberId, $staffId, $verifierId, (string) $requester->getKey(), (string) $approver->getKey()])->unique())->toHaveCount(5);

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-JOURNEY', 'Bank ••NEY',
        'user', $verifierId, 'staff',
    );

    $approved = journeyApprove($award, $staffId);

    $request = RequestRewardPayoutAction::run(
        $approved, $payee, 'bank_transfer', 'program', 'user', (string) $requester->getKey(), 'staff',
    );
    $request = ApproveRewardPayoutAction::run(
        $request, 'user', (string) $approver->getKey(), 'staff', 'finance-ok',
    );
    $request = SubmitRewardPayoutAction::run($request, 'user', $staffId, 'staff');
    $request = ConfirmRewardPayoutAction::run(
        $request, ['bank_reference' => 'BANK-JOURNEY-1', 'transferred_at' => CarbonImmutable::now('UTC')->toIso8601String()],
        'user', $staffId, 'staff',
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed);

    $fulfilled = journeyFulfill($approved->refresh(), $staffId);
    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);

    $report = journeyCorrect($key, $staffId);

    expect($report['reversed'])->toHaveCount(1)
        ->and($report['recoveries'])->toHaveCount(1)
        ->and($report['errors'])->toBe([])
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Reversed);

    $recovery = RewardRecovery::query()->where('award_id', $award->getKey())->firstOrFail();
    expect($recovery->status)->toBe(RewardRecoveryStatus::Open);

    $settled = SettleRewardRecoveryAction::run(
        $recovery, ['recovery_proof' => 'BANK-RETURN-1'], 'user', $staffId, 'staff',
    );

    expect($settled->status)->toBe(RewardRecoveryStatus::Settled);
});

it('journeys a benefit award from catalog setup to issued grant to revocation', function () {
    [$member, $staff] = journeyUsers();
    $staffId = (string) $staff->getKey();
    $memberId = (string) $member->getKey();
    [$program, $version] = journeyProgram('journey-benefit', RewardProgramMode::Benefit, $staffId);

    [$award, $key] = journeyCheckinAward($program, $version, $member, $staff);

    $approved = journeyApprove($award, $staffId);
    $fulfilled = journeyFulfill($approved, $staffId);

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);

    $grant = RewardBenefitGrant::query()->where('award_id', $award->getKey())->firstOrFail();
    expect($grant->status)->toBe(RewardBenefitGrantStatus::Reserved);

    FulfillRewardBenefitAction::run(
        $grant, ['handoff_reference' => 'DESK-JOURNEY-1'], 'user', $staffId, 'staff',
    );

    expect($grant->refresh()->status)->toBe(RewardBenefitGrantStatus::Issued);

    // The handed-over benefit comes back first: recall proof, then revoke.
    RecordRewardBenefitRecallAction::run($grant->refresh(), 'RECALL-JOURNEY-1', 'user', $staffId, 'staff');
    RevokeRewardBenefitGrantAction::run($grant->refresh(), 'user', $staffId, 'staff', 'Source withdrawn before use.');

    expect($grant->refresh()->status)->toBe(RewardBenefitGrantStatus::Revoked);

    $report = journeyCorrect($key, $staffId);

    expect($report['reversed'])->toHaveCount(1)
        ->and($report['recoveries'])->toBe([])
        ->and($report['errors'])->toBe([])
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Reversed);
});

it('journeys a combined award across all three components at once', function () {
    [$member, $staff] = journeyUsers();
    $staffId = (string) $staff->getKey();
    $memberId = (string) $member->getKey();
    [$program, $version] = journeyProgram('journey-combined', RewardProgramMode::Combined, $staffId);
    [, $key] = journeyAward($program, $version, $member);

    $awards = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('recipient_id', $memberId)
        ->get();

    expect($awards->pluck('component.value')->sort()->values()->all())
        ->toBe(['badge', 'points', 'recognition']);

    foreach ($awards as $award) {
        $approved = journeyApprove($award, $staffId);
        $fulfilled = journeyFulfill($approved, $staffId);
        expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);
    }

    $buckets = RewardPointLedger::balancesFor('user', $memberId, (string) $program->family_key, 'pts', CarbonImmutable::now('UTC'));
    expect($buckets->available)->toBe(10);

    $report = journeyCorrect($key, $staffId);

    expect($report['reversed'])->toHaveCount(3)
        ->and($report['errors'])->toBe([]);
});
