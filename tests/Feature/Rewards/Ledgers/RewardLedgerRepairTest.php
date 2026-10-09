<?php

use App\Actions\Rewards\AdvanceRewardAppealAction;
use App\Actions\Rewards\AppendRewardAdjustmentAction;
use App\Actions\Rewards\CreditRewardPointsAction;
use App\Actions\Rewards\DebitRewardPointsAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\ExpireRewardPointsAction;
use App\Actions\Rewards\OpenRewardAppealAction;
use App\Actions\Rewards\RecordRewardMilestoneContributionAction;
use App\Actions\Rewards\RefundRewardPointsAction;
use App\Actions\Rewards\ReleaseRewardPointsAction;
use App\Actions\Rewards\ReserveRewardPointsAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardAdjustmentKind;
use App\Enums\Rewards\RewardAppealStatus;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardEvaluationOutcome;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Enums\Rewards\RewardSourceFraudStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\RewardAward;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Models\RewardSourceComponentClaim;
use App\Support\Rewards\RewardOperationKeys;
use App\Support\Rewards\RewardPointLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

function repairIdentity(string $suffix = ''): array
{
    return ['user', (string) Str::uuid(), 'repair-'.$suffix.Str::lower(Str::random(4)), 'pts'];
}

it('R1 refuses reserve and debit after deadline without any expiry job', function () {
    [$type, $recipient, $family, $unit] = repairIdentity('r1a');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-01T00:00:00Z'), null, null, 'op:r1-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $after = CarbonImmutable::parse('2026-07-02T00:00:00Z');
    $buckets = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, $after);

    expect($buckets->available)->toBe(0)
        ->and($buckets->expired)->toBe(100)
        ->and($buckets->reserved)->toBe(0);

    expect(fn () => ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 10, 'rsv:r1-after', 'hold', $after))
        ->toThrow(InvalidArgumentException::class, 'Insufficient available');

    expect(fn () => DebitRewardPointsAction::run($type, $recipient, $family, $unit, 10, 'spend:r1-after', 'spend', null, $after))
        ->toThrow(InvalidArgumentException::class, 'Insufficient available');

    expect(RewardPointMovement::query()->where('kind', 'expire')->count())->toBe(0);

    $expired = ExpireRewardPointsAction::run($type, $recipient, $family, $unit, $after);

    expect($expired)->toHaveCount(1)
        ->and((int) $expired[0]->delta)->toBe(100);

    $replay = ExpireRewardPointsAction::run($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-07-03T00:00:00Z'));

    expect($replay)->toBe([])
        ->and(RewardPointMovement::query()->where('kind', 'expire')->count())->toBe(1);

    $settled = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-07-04T00:00:00Z'));

    expect($settled->available)->toBe(0)
        ->and($settled->expired)->toBe(100);
});

it('R1 preserves reserved obligations across expiry until resolution', function () {
    [$type, $recipient, $family, $unit] = repairIdentity('r1b');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-01T00:00:00Z'), null, null, 'op:r1b-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 30, 'rsv:r1b-1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $after = CarbonImmutable::parse('2026-07-02T00:00:00Z');
    $buckets = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, $after);

    expect($buckets->available)->toBe(0)
        ->and($buckets->reserved)->toBe(30)
        ->and($buckets->expired)->toBe(70);

    $captured = DebitRewardPointsAction::run($type, $recipient, $family, $unit, 30, 'spend:r1b-cap', 'capture', 'rsv:r1b-1', $after);

    expect($captured)->toHaveCount(1);

    $settled = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-07-03T00:00:00Z'));

    expect($settled->reserved)->toBe(0)
        ->and($settled->spent)->toBe(30)
        ->and($settled->expired)->toBe(70);

    [$type2, $recipient2, $family2, $unit2] = repairIdentity('r1c');
    CreditRewardPointsAction::run($type2, $recipient2, $family2, $unit2, 50, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-01T00:00:00Z'), null, null, 'op:r1c-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    ReserveRewardPointsAction::run($type2, $recipient2, $family2, $unit2, 50, 'rsv:r1c-1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $released = ReleaseRewardPointsAction::run('rsv:r1c-1', 'late-cancel', $after);

    expect($released[0]->kind->value)->toBe('expire');

    $afterRelease = RewardPointLedger::balancesFor($type2, $recipient2, $family2, $unit2, CarbonImmutable::parse('2026-07-03T00:00:00Z'));

    expect($afterRelease->reserved)->toBe(0)
        ->and($afterRelease->expired)->toBe(50)
        ->and($afterRelease->available)->toBe(0);
});

it('R1 derives fulfillment expiry from frozen terms and never silently perpetuates', function () {
    [$program, $version, $enrollment, $memberId] = RewardLedgerFixtures::seedActiveEnrolled('repair-r1d');
    $acceptance = RewardEnrollmentAcceptance::query()->where('enrollment_id', $enrollment->getKey())->orderByDesc('seq')->firstOrFail();
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-r1d-1', $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-r1d-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $award = $result->award->refresh();
    $staff = RewardLedgerFixtures::staffId();

    $fulfilled = (new TransitionRewardAwardAction(null, $reader))->handle(
        $award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);

    $lot = RewardPointLot::query()->where('award_id', $award->getKey())->firstOrFail();

    expect($lot->expires_at)->toBeNull();

    $fact2 = RewardLedgerFixtures::fact('src-r1d-2', $memberId);
    $snapshots2 = RewardLedgerFixtures::frozenAwardSnapshots($version, $acceptance, $fact2);
    $award2 = RewardAward::factory()->create([
        'program_id' => $program->getKey(),
        'policy_version_id' => $version->getKey(),
        'enrollment_id' => $enrollment->getKey(),
        'acceptance_id' => $acceptance->getKey(),
        'recipient_id' => $memberId,
        'source_business_key' => 'src-r1d-2',
        'family_key' => $program->family_key,
        'component' => RewardComponent::Points,
        'status' => RewardAwardStatus::Approved,
        'amount_snapshot' => ['type' => 'points', 'points' => 10, 'unit' => 'pts', 'expires_in_days' => 30],
        'policy_snapshot' => $snapshots2['policy'],
        'consent_snapshot' => $snapshots2['consent'],
        'source_snapshot' => $snapshots2['source'],
        'held_at' => null,
        'approved_at' => CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    ]);

    $reader->seed($fact2);

    $fulfilled2 = (new TransitionRewardAwardAction(null, $reader))->handle(
        $award2, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill-exp', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($fulfilled2->status)->toBe(RewardAwardStatus::Fulfilled);

    $lot2 = RewardPointLot::query()->where('award_id', $award2->getKey())->firstOrFail();

    expect(CarbonImmutable::parse($lot2->expires_at)->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-07-18T00:00:00Z')->toIso8601String());

    $fact3 = RewardLedgerFixtures::fact('src-r1d-3', $memberId);
    $snapshots3 = RewardLedgerFixtures::frozenAwardSnapshots($version, $acceptance, $fact3);
    $award3 = RewardAward::factory()->create([
        'program_id' => $program->getKey(),
        'policy_version_id' => $version->getKey(),
        'enrollment_id' => $enrollment->getKey(),
        'acceptance_id' => $acceptance->getKey(),
        'recipient_id' => $memberId,
        'source_business_key' => 'src-r1d-3',
        'family_key' => $program->family_key,
        'component' => RewardComponent::Points,
        'status' => RewardAwardStatus::Approved,
        'amount_snapshot' => ['type' => 'points', 'points' => 10, 'unit' => 'pts', 'expires_in_days' => 30],
        'policy_snapshot' => $snapshots3['policy'],
        'consent_snapshot' => $snapshots3['consent'],
        'source_snapshot' => $snapshots3['source'],
        'held_at' => null,
        'approved_at' => CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    ]);

    $reader->seed($fact3);

    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle(
        $award3, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill-wrong', null, null,
        CarbonImmutable::parse('2026-06-18T00:00:00Z'), CarbonImmutable::parse('2026-12-31T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'frozen published terms');
});

it('R2 collapses same-operation refund replays and refuses changed terms', function () {
    [$type, $recipient, $family, $unit] = repairIdentity('r2a');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 50, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:r2-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    DebitRewardPointsAction::run($type, $recipient, $family, $unit, 20, 'spend:r2-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $first = RefundRewardPointsAction::run('refund:r2-1', 'spend:r2-1', 12, 'partial', CarbonImmutable::parse('2026-06-06T00:00:00Z'));
    $replay = RefundRewardPointsAction::run('refund:r2-1', 'spend:r2-1', 12, 'partial', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

    expect($replay)->toHaveCount(count($first))
        ->and((string) $replay[0]->getKey())->toBe((string) $first[0]->getKey())
        ->and(RewardPointLot::query()->count())->toBe(2);

    expect(fn () => RefundRewardPointsAction::run('refund:r2-1', 'spend:r2-1', 5, 'changed-amount', CarbonImmutable::parse('2026-06-07T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different amount');

    $second = RefundRewardPointsAction::run('refund:r2-2', 'spend:r2-1', 8, 'remainder', CarbonImmutable::parse('2026-06-07T00:00:00Z'));

    expect($second)->toHaveCount(1);

    $buckets = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-06-08T00:00:00Z'));

    expect($buckets->available)->toBe(50)
        ->and($buckets->spent)->toBe(20);

    expect(fn () => RefundRewardPointsAction::run('refund:r2-3', 'spend:r2-1', 1, 'over', CarbonImmutable::parse('2026-06-09T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unrefunded debited amount');
});

it('R2 allocates multi-lot refunds with inherited expiries and after-expiry truthfulness', function () {
    [$type, $recipient, $family, $unit] = repairIdentity('r2b');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 30, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-10T00:00:00Z'), null, null, 'op:r2b-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 30, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-09-10T00:00:00Z'), null, null, 'op:r2b-b', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    DebitRewardPointsAction::run($type, $recipient, $family, $unit, 40, 'spend:r2b-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $refunds = RefundRewardPointsAction::run('refund:r2b-1', 'spend:r2b-1', 35, 'multi', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

    expect($refunds)->toHaveCount(2);

    $lots = RewardPointLot::query()->orderBy('credited_at')->orderBy('id')->get();
    $refundLots = $lots->slice(2)->values();

    expect($refundLots)->toHaveCount(2);

    $expiries = $refundLots->map(fn (RewardPointLot $lot): ?string => $lot->expires_at === null ? null : CarbonImmutable::parse($lot->expires_at)->toIso8601String())->sort()->values()->all();

    expect($expiries)->toBe([
        CarbonImmutable::parse('2026-07-10T00:00:00Z')->toIso8601String(),
        CarbonImmutable::parse('2026-09-10T00:00:00Z')->toIso8601String(),
    ]);

    [$type2, $recipient2, $family2, $unit2] = repairIdentity('r2c');
    CreditRewardPointsAction::run($type2, $recipient2, $family2, $unit2, 20, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-01T00:00:00Z'), null, null, 'op:r2c-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    DebitRewardPointsAction::run($type2, $recipient2, $family2, $unit2, 20, 'spend:r2c-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $late = RefundRewardPointsAction::run('refund:r2c-1', 'spend:r2c-1', 10, 'after-expiry', CarbonImmutable::parse('2026-07-05T00:00:00Z'));

    expect($late)->toHaveCount(1);

    $lateLot = RewardPointLot::query()->whereKey((string) $late[0]->lot_id)->firstOrFail();

    expect(CarbonImmutable::parse($lateLot->expires_at)->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-07-01T00:00:00Z')->toIso8601String());

    $buckets = RewardPointLedger::balancesFor($type2, $recipient2, $family2, $unit2, CarbonImmutable::parse('2026-07-06T00:00:00Z'));

    expect($buckets->available)->toBe(0)
        ->and($buckets->expired)->toBe(10)
        ->and($buckets->spent)->toBe(20);
});

it('R3 refuses mismatched replay across recipient family unit and amount', function () {
    [$type, $recipient, $family, $unit] = repairIdentity('r3a');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:r3-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 20, 'rsv:r3-1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $other = (string) Str::uuid();
    CreditRewardPointsAction::run($type, $other, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:r3-b', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    expect(fn () => ReserveRewardPointsAction::run($type, $other, $family, $unit, 20, 'rsv:r3-1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different recipient');

    expect(fn () => ReserveRewardPointsAction::run($type, $recipient, 'other-family', $unit, 20, 'rsv:r3-1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => DebitRewardPointsAction::run($type, $recipient, $family, $unit, 10, 'spend:r3-1', 'spend', null, CarbonImmutable::parse('2026-06-06T00:00:00Z')))
        ->not->toThrow(InvalidArgumentException::class);

    expect(fn () => DebitRewardPointsAction::run($type, $other, $family, $unit, 10, 'spend:r3-1', 'spend', null, CarbonImmutable::parse('2026-06-06T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different recipient');

    expect(fn () => CreditRewardPointsAction::run($type, $other, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:r3-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different recipient');

    $key = RewardOperationKeys::adjustment('adjustment', 'award', (string) Str::uuid(), 'r3-nonce');
    $awardId = (string) Str::uuid();
    $staff = RewardLedgerFixtures::staffId();

    AppendRewardAdjustmentAction::run(RewardAdjustmentKind::Adjustment, $awardId, null, null, $key, ['type' => 'points', 'points' => 5, 'unit' => 'pts'], 'user', $staff, 'staff', 'open', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));

    expect(fn () => AppendRewardAdjustmentAction::run(RewardAdjustmentKind::Adjustment, $awardId, null, null, $key, ['type' => 'points', 'points' => 6, 'unit' => 'pts'], 'user', $staff, 'staff', 'open', null, CarbonImmutable::parse('2026-06-20T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different terms');
});

it('R4 records exact prior states through full award and appeal chains', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('repair-r4');
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-r4-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-r4-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Held);

    $staff = RewardLedgerFixtures::staffId();
    $award = $result->award->refresh();

    TransitionRewardAwardAction::run($award, RewardAwardStatus::PendingReview, 'user', $staff, 'staff', 'needs-review', null, 'op:r4-pending', CarbonImmutable::parse('2026-06-16T00:00:00Z'));
    (new TransitionRewardAwardAction(null, $reader))->handle($award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'verified-ok', null, 'op:r4-approved', CarbonImmutable::parse('2026-06-17T00:00:00Z'));
    (new TransitionRewardAwardAction(null, $reader))->handle($award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill-ok', null, 'op:r4-fulfilled', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

    $entries = $award->refresh()->entries()->orderBy('seq')->get();

    expect($entries->pluck('from_status')->all())->toBe(['none', 'held', 'pending_review', 'approved'])
        ->and($entries->pluck('to_status')->all())->toBe(['held', 'pending_review', 'approved', 'fulfilled'])
        ->and($entries[1]->source_operation_id)->toBe('op:r4-pending')
        ->and($entries[1]->actor_id)->toBe($staff);

    $replayed = (new TransitionRewardAwardAction(null, $reader))->handle($award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill-ok', null, 'op:r4-fulfilled', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

    expect($replayed->entries()->count())->toBe(4);

    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle($award, RewardAwardStatus::Reversed, 'user', $staff, 'staff', 'reverse', null, 'op:r4-fulfilled', CarbonImmutable::parse('2026-06-19T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different transition');

    $opened = OpenRewardAppealAction::run($award, 'user', $memberId, 'member', 'please-recheck', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    $reviewer = RewardLedgerFixtures::staffId();

    AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $reviewer, 'staff', 'taking-case', null, CarbonImmutable::parse('2026-06-21T00:00:00Z'));
    AdvanceRewardAppealAction::run($opened, RewardAppealStatus::Upheld, 'user', $reviewer, 'staff', 'correct', null, CarbonImmutable::parse('2026-06-22T00:00:00Z'));

    $decisions = $opened->refresh()->decisions()->orderBy('seq')->get();

    expect($decisions->pluck('from_status')->all())->toBe(['none', 'open', 'in_review'])
        ->and($decisions->pluck('to_status')->all())->toBe(['open', 'in_review', 'upheld']);
});

it('R5 refuses approval and fulfillment after source invalidation', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('repair-r5a');
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-r5a-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-r5a-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $staff = RewardLedgerFixtures::staffId();

    $reader->seed(RewardLedgerFixtures::fact('src-r5a-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isCancelled' => true]));

    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'approve', null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'source_cancelled');

    expect($result->award->refresh()->status)->toBe(RewardAwardStatus::Held);

    [$program2, , , $member2] = RewardLedgerFixtures::seedActiveEnrolled('repair-r5b');
    $reader2 = new FakeRewardSourceFactReader;
    $reader2->seed(RewardLedgerFixtures::fact('src-r5b-1', $member2));

    $result2 = (new EvaluateRewardEntitlementAction($reader2))->handle(
        $program2, RewardComponent::Points, 'user', $member2, RewardBeneficiaryRole::Recipient,
        'src-r5b-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $reader2->seed(RewardLedgerFixtures::fact('src-r5b-1', $member2, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['fraudStatus' => RewardSourceFraudStatus::Confirmed]));

    expect(fn () => (new TransitionRewardAwardAction(null, $reader2))->handle($result2->award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'fraud_confirmed');

    expect($result2->award->refresh()->status)->toBe(RewardAwardStatus::Approved);

    expect(fn () => TransitionRewardAwardAction::run($result2->award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill-null', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unknown_source');
});

it('R5 overturn acquires claims and caps and refuses incompatible or exhausted rivals', function () {
    [$programA, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('repair-r5c');
    [$programB] = RewardLedgerFixtures::seedVersionedProgram('repair-r5d', RewardProgramMode::Points);

    TransitionRewardProgramStatusAction::run($programB, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($programB, RewardProgramStatus::Active);
    $programB = $programB->refresh();

    $versionB = $programB->versions()->orderBy('version')->firstOrFail();
    EnrollRewardMemberAction::run($programB, 'user', $memberId, $versionB, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-r5c-1', $memberId));

    $first = (new EvaluateRewardEntitlementAction($reader))->handle(
        $programA, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-r5c-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($first->outcome)->toBe(RewardEvaluationOutcome::Awarded);

    $second = (new EvaluateRewardEntitlementAction($reader))->handle(
        $programB, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-r5c-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($second->outcome)->toBe(RewardEvaluationOutcome::Rejected);

    $opened = OpenRewardAppealAction::run($second->award, 'user', $memberId, 'member', 'please', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    $reviewer = RewardLedgerFixtures::staffId();

    AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $reviewer, 'admin', 'taking', null, CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect(fn () => (new AdvanceRewardAppealAction($reader))->handle($opened, RewardAppealStatus::Overturned, 'user', $reviewer, 'admin', 'overturn', ['merit_override' => ['enrollment_prerequisites']], CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'stacking_conflict');

    expect(RewardSourceComponentClaim::query()->count())->toBe(1);

    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle($second->award, RewardAwardStatus::Approved, 'user', $reviewer, 'admin', 'direct', null, null, CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'appeal-merit-override');
});

it('R5 keeps held approvals single-claim and single-cap', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('repair-r5e');
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-r5e-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-r5e-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $claimsBefore = RewardSourceComponentClaim::query()->count();
    $staff = RewardLedgerFixtures::staffId();

    (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'approve', null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z'));

    expect(RewardSourceComponentClaim::query()->count())->toBe($claimsBefore);
});

it('R6 refuses unpublished unknown and unqualified milestones and binds published terms', function () {
    [$program, $memberId] = (function (): array {
        [$p, , , $m] = RewardLedgerFixtures::seedActiveEnrolled('repair-r6a');

        return [$p, $m];
    })();

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-r6-1', $memberId, '2026-06-10T00:00:00Z'));

    expect(fn () => (new RecordRewardMilestoneContributionAction($reader))->handle(
        'user', $memberId, $program->family_key, 'ghost', 2, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        'src-r6-1', 'user', $memberId, RewardBeneficiaryRole::Participant,
        RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        (string) $program->getKey(),
    ))->toThrow(InvalidArgumentException::class, 'not published');

    expect(fn () => (new RecordRewardMilestoneContributionAction(new FakeRewardSourceFactReader))->handle(
        'user', $memberId, $program->family_key, 'ghost', 2, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        'src-r6-unknown', 'user', $memberId, RewardBeneficiaryRole::Participant,
        RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        (string) $program->getKey(),
    ))->toThrow(InvalidArgumentException::class, 'unknown_source');

    expect(fn () => (new RecordRewardMilestoneContributionAction($reader))->handle(
        'user', $memberId, $program->family_key, 'ghost', 2, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        'src-r6-1', 'user', $memberId, RewardBeneficiaryRole::Participant,
        RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        (string) Str::uuid(),
    ))->toThrow(InvalidArgumentException::class, 'Unknown reward program');

    $badReader = new FakeRewardSourceFactReader;
    $badReader->seed(RewardLedgerFixtures::fact('src-r6-bot', $memberId, '2026-06-10T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isBot' => true]));

    [$program2, , , $member2] = RewardLedgerFixtures::seedActiveEnrolled('repair-r6b', RewardProgramMode::Points, ['milestones' => [['key' => 'two', 'target_count' => 2, 'window_days' => 30, 'reward' => ['type' => 'points', 'points' => 25, 'unit' => 'pts']]]]);

    expect(fn () => (new RecordRewardMilestoneContributionAction($badReader))->handle(
        'user', $member2, $program2->family_key, 'two', 2, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        'src-r6-bot', 'user', $member2, RewardBeneficiaryRole::Participant,
        RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        (string) $program2->getKey(),
    ))->toThrow(InvalidArgumentException::class, 'bot_excluded');

    $goodReader = new FakeRewardSourceFactReader;
    $goodReader->seed(RewardLedgerFixtures::fact('src-r6-ok', $member2, '2026-06-10T00:00:00Z'));

    expect(fn () => (new RecordRewardMilestoneContributionAction($goodReader))->handle(
        'user', $member2, $program2->family_key, 'two', 3, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        'src-r6-ok', 'user', $member2, RewardBeneficiaryRole::Participant,
        RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        (string) $program2->getKey(),
    ))->toThrow(InvalidArgumentException::class, 'published definition');
});

it('R4 leaves no orphan entry or status when fulfillment fails mid-transition', function () {
    [$program, $version, $enrollment, $memberId] = RewardLedgerFixtures::seedActiveEnrolled('repair-r4b');
    $acceptance = RewardEnrollmentAcceptance::query()->where('enrollment_id', $enrollment->getKey())->orderByDesc('seq')->firstOrFail();

    $fact = RewardLedgerFixtures::fact('src-r4b-1', $memberId);
    $snapshots = RewardLedgerFixtures::frozenAwardSnapshots($version, $acceptance, $fact);
    $award = RewardAward::factory()->create([
        'program_id' => $program->getKey(),
        'policy_version_id' => $version->getKey(),
        'enrollment_id' => $enrollment->getKey(),
        'acceptance_id' => $acceptance->getKey(),
        'recipient_id' => $memberId,
        'source_business_key' => 'src-r4b-1',
        'family_key' => $program->family_key,
        'component' => RewardComponent::Points,
        'status' => RewardAwardStatus::Approved,
        'amount_snapshot' => ['type' => 'points'],
        'policy_snapshot' => $snapshots['policy'],
        'consent_snapshot' => $snapshots['consent'],
        'source_snapshot' => $snapshots['source'],
        'held_at' => null,
        'approved_at' => CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    ]);

    $reader = new FakeRewardSourceFactReader;
    $reader->seed($fact);

    $staff = RewardLedgerFixtures::staffId();
    $entriesBefore = $award->entries()->count();

    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle(
        $award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'missing points/unit');

    expect($award->refresh()->status)->toBe(RewardAwardStatus::Approved)
        ->and($award->fulfilled_at)->toBeNull()
        ->and($award->entries()->count())->toBe($entriesBefore);
});

it('R5 refuses overturn when the frozen cap quota is exhausted', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled(
        'repair-r5f', RewardProgramMode::Points, ['caps' => [['scope' => 'recipient_program_month', 'limit' => 1]]],
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-r5f-1', $memberId));
    $reader->seed(RewardLedgerFixtures::fact('src-r5f-2', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['verification' => RewardVerificationLevel::Unverified]));

    $first = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-r5f-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($first->outcome)->toBe(RewardEvaluationOutcome::Awarded);

    $second = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-r5f-2', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($second->outcome)->toBe(RewardEvaluationOutcome::Rejected);

    // The source later verifies, so the overturn reaches the frozen cap
    // quota instead of failing its source gates.
    $reader->seed(RewardLedgerFixtures::fact('src-r5f-2', $memberId));

    $opened = OpenRewardAppealAction::run($second->award, 'user', $memberId, 'member', 'please', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    $reviewer = RewardLedgerFixtures::staffId();

    AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $reviewer, 'admin', 'taking', null, CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect(fn () => (new AdvanceRewardAppealAction($reader))->handle($opened, RewardAppealStatus::Overturned, 'user', $reviewer, 'admin', 'overturn', null, CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'cap_exceeded');

    expect($second->award->refresh()->status)->toBe(RewardAwardStatus::Rejected);
});
