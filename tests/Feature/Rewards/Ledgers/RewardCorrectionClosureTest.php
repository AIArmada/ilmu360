<?php

use App\Actions\Rewards\ClawBackAwardPointsAction;
use App\Actions\Rewards\DebitRewardPointsAction;
use App\Actions\Rewards\ExpireRewardPointsAction;
use App\Actions\Rewards\InvalidateRewardMilestoneContributionAction;
use App\Actions\Rewards\MarkRewardReservationSubmittedAction;
use App\Actions\Rewards\RecordRewardMilestoneContributionAction;
use App\Actions\Rewards\RefundRewardPointsAction;
use App\Actions\Rewards\ReleaseRewardPointsAction;
use App\Actions\Rewards\ReserveRewardPointsAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Enums\Rewards\RewardAdjustmentKind;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardMilestoneStatus;
use App\Enums\Rewards\RewardPointLotStatus;
use App\Enums\Rewards\RewardProgramMode;
use App\Models\RewardAdjustment;
use App\Models\RewardAward;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardMilestoneContribution;
use App\Models\RewardMilestoneCycle;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Models\RewardPointReservationSubmission;
use App\Support\Rewards\RewardPointLedger;
use Carbon\CarbonImmutable;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardReservationResolver;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

/**
 * @return array{0: mixed, 1: mixed, 2: mixed, 3: RewardEnrollmentAcceptance, 4: string, 5: FakeRewardSourceFactReader, 6: string}
 */
function correctionSetup(string $prefix): array
{
    [$program, $version, $enrollment, $memberId] = RewardLedgerFixtures::seedActiveEnrolled($prefix);
    $acceptance = RewardEnrollmentAcceptance::query()->where('enrollment_id', $enrollment->getKey())->orderByDesc('seq')->firstOrFail();
    $reader = new FakeRewardSourceFactReader;
    $staff = RewardLedgerFixtures::staffId();

    return [$program, $version, $enrollment, $acceptance, $memberId, $reader, $staff];
}

function correctionFulfill(
    array $setup,
    string $source,
    int $points,
    ?string $expiresAt = null,
    string $fulfilledAt = '2026-06-18T00:00:00Z',
): RewardAward {
    [$program, $version, $enrollment, $acceptance, $memberId, $reader, $staff] = $setup;

    $fact = RewardLedgerFixtures::fact($source, $memberId);
    $snapshots = RewardLedgerFixtures::frozenAwardSnapshots($version, $acceptance, $fact);
    $amount = ['type' => 'points', 'points' => $points, 'unit' => 'pts'];

    if ($expiresAt !== null) {
        $amount['expires_at'] = $expiresAt;
    }

    $award = RewardAward::factory()->create([
        'program_id' => $program->getKey(),
        'policy_version_id' => $version->getKey(),
        'enrollment_id' => $enrollment->getKey(),
        'acceptance_id' => $acceptance->getKey(),
        'recipient_id' => $memberId,
        'source_business_key' => $source,
        'family_key' => $program->family_key,
        'component' => RewardComponent::Points,
        'status' => RewardAwardStatus::Approved,
        'amount_snapshot' => $amount,
        'policy_snapshot' => $snapshots['policy'],
        'consent_snapshot' => $snapshots['consent'],
        'source_snapshot' => $snapshots['source'],
        'held_at' => null,
        'approved_at' => CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    ]);

    $reader->seed($fact);

    return (new TransitionRewardAwardAction(null, $reader))->handle(
        $award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill',
        null, null, CarbonImmutable::parse($fulfilledAt),
    );
}

function correctionReverse(
    RewardAward $award,
    string $staff,
    string $at = '2026-06-20T00:00:00Z',
    ?FakeRewardReservationResolver $resolver = null,
): RewardAward {
    return (new TransitionRewardAwardAction(null, null, $resolver))->handle(
        $award, RewardAwardStatus::Reversed, 'user', $staff, 'staff', 'source-error',
        null, null, CarbonImmutable::parse($at),
    );
}

it('D1 leaves no dispute when a full refund precedes reversal', function () {
    $setup = correctionSetup('corr-d1f');
    [, , , , $memberId, , $staff] = $setup;
    $award = correctionFulfill($setup, 'src-d1f-1', 100);

    DebitRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 40, 'spend:d1f-1', 'spend', null, CarbonImmutable::parse('2026-06-19T00:00:00Z'));
    RefundRewardPointsAction::run('refund:d1f-1', 'spend:d1f-1', 40, 'full-refund', CarbonImmutable::parse('2026-06-19T12:00:00Z'));

    $resolver = new FakeRewardReservationResolver;
    $reversed = correctionReverse($award->refresh(), $staff, '2026-06-20T00:00:00Z', $resolver);

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed)
        ->and(RewardAdjustment::query()->where('award_id', $award->getKey())->count())->toBe(0)
        ->and($resolver->readKeys())->toBe([]);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($buckets->available)->toBe(0)
        ->and($buckets->spent)->toBe(40)
        ->and($buckets->reserved)->toBe(0);

    expect(RewardPointLot::query()->where('award_id', $award->getKey())->count())->toBe(2)
        ->and(RewardPointLot::query()->where('award_id', $award->getKey())->where('status', RewardPointLotStatus::Depleted)->count())->toBe(2)
        ->and((int) RewardPointMovement::query()->where('kind', 'adjust')->sum('delta'))->toBe(-100);

    // Replays collapse without another liability: the transition replays onto
    // the reversed award and a direct settlement rerun is a stable no-op.
    $entriesBefore = $reversed->entries()->count();
    $movementsBefore = RewardPointMovement::query()->count();

    $again = correctionReverse($reversed->refresh(), $staff);

    expect($again->status)->toBe(RewardAwardStatus::Reversed)
        ->and($again->entries()->count())->toBe($entriesBefore);

    $rerun = ClawBackAwardPointsAction::run($award, 'user', $staff, 'staff', 'source-error', CarbonImmutable::parse('2026-06-20T00:00:00Z'));

    expect($rerun)->toBe(['clawed_points' => 0, 'consumed_points' => 0, 'dispute_id' => null])
        ->and(RewardPointMovement::query()->count())->toBe($movementsBefore)
        ->and(RewardAdjustment::query()->where('award_id', $award->getKey())->count())->toBe(0);
});

it('D1 disputes only the unrefunded remainder after a partial refund', function () {
    $setup = correctionSetup('corr-d1p');
    [, , , , $memberId, , $staff] = $setup;
    $award = correctionFulfill($setup, 'src-d1p-1', 100);

    DebitRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 40, 'spend:d1p-1', 'spend', null, CarbonImmutable::parse('2026-06-19T00:00:00Z'));
    RefundRewardPointsAction::run('refund:d1p-1', 'spend:d1p-1', 10, 'partial-refund', CarbonImmutable::parse('2026-06-19T12:00:00Z'));

    $reversed = correctionReverse($award->refresh(), $staff);

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed);

    $dispute = RewardAdjustment::query()->where('award_id', $award->getKey())->firstOrFail();

    expect($dispute->kind)->toBe(RewardAdjustmentKind::Dispute)
        ->and($dispute->amount_snapshot['consumed_points'])->toBe(30)
        ->and($dispute->amount_snapshot['gross_spent_points'])->toBe(40)
        ->and($dispute->amount_snapshot['refunded_points'])->toBe(10)
        ->and($dispute->amount_snapshot['clawed_points'])->toBe(70);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($buckets->available)->toBe(0)
        ->and($buckets->spent)->toBe(40);
});

it('D1 attributes multi-award debits and refunds per award', function () {
    $setup = correctionSetup('corr-d1m');
    [, , , , $memberId, , $staff] = $setup;

    $awardA = correctionFulfill($setup, 'src-d1m-a', 100, '2026-07-01T00:00:00Z');
    $awardB = correctionFulfill($setup, 'src-d1m-b', 100, '2026-09-01T00:00:00Z');

    DebitRewardPointsAction::run('user', $memberId, $awardA->family_key, 'pts', 120, 'spend:d1m-1', 'spend', null, CarbonImmutable::parse('2026-06-19T00:00:00Z'));
    RefundRewardPointsAction::run('refund:d1m-1', 'spend:d1m-1', 50, 'partial-refund', CarbonImmutable::parse('2026-06-19T12:00:00Z'));

    expect(RewardPointLot::query()->where('award_id', $awardA->getKey())->count())->toBe(2)
        ->and(RewardPointLot::query()->where('award_id', $awardB->getKey())->count())->toBe(1);

    $reversedA = correctionReverse($awardA->refresh(), $staff);

    expect($reversedA->status)->toBe(RewardAwardStatus::Reversed);

    $disputeA = RewardAdjustment::query()->where('award_id', $awardA->getKey())->firstOrFail();

    expect($disputeA->amount_snapshot['consumed_points'])->toBe(50)
        ->and($disputeA->amount_snapshot['gross_spent_points'])->toBe(100)
        ->and($disputeA->amount_snapshot['refunded_points'])->toBe(50)
        ->and($disputeA->amount_snapshot['clawed_points'])->toBe(50);

    expect($awardB->refresh()->status)->toBe(RewardAwardStatus::Fulfilled);

    $mid = RewardPointLedger::balancesFor('user', $memberId, $awardA->family_key, 'pts', CarbonImmutable::parse('2026-06-20T12:00:00Z'));

    expect($mid->available)->toBe(80)
        ->and($mid->spent)->toBe(120);

    $reversedB = correctionReverse($awardB->refresh(), $staff);

    expect($reversedB->status)->toBe(RewardAwardStatus::Reversed);

    $disputeB = RewardAdjustment::query()->where('award_id', $awardB->getKey())->firstOrFail();

    expect($disputeB->amount_snapshot['consumed_points'])->toBe(20)
        ->and($disputeB->amount_snapshot['clawed_points'])->toBe(80);

    $final = RewardPointLedger::balancesFor('user', $memberId, $awardA->family_key, 'pts', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($final->available)->toBe(0)
        ->and($final->spent)->toBe(120);
});

it('D1 nets repeat spend and refund cycles', function () {
    $setup = correctionSetup('corr-d1c');
    [, , , , $memberId, , $staff] = $setup;
    $award = correctionFulfill($setup, 'src-d1c-1', 100);

    DebitRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 40, 'spend:d1c-1', 'spend', null, CarbonImmutable::parse('2026-06-19T00:00:00Z'));
    RefundRewardPointsAction::run('refund:d1c-1', 'spend:d1c-1', 40, 'full-refund', CarbonImmutable::parse('2026-06-19T06:00:00Z'));
    DebitRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 30, 'spend:d1c-2', 'spend', null, CarbonImmutable::parse('2026-06-19T12:00:00Z'));

    $reversed = correctionReverse($award->refresh(), $staff);

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed);

    $dispute = RewardAdjustment::query()->where('award_id', $award->getKey())->firstOrFail();

    expect($dispute->amount_snapshot['consumed_points'])->toBe(30)
        ->and($dispute->amount_snapshot['gross_spent_points'])->toBe(70)
        ->and($dispute->amount_snapshot['refunded_points'])->toBe(40)
        ->and($dispute->amount_snapshot['clawed_points'])->toBe(70);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($buckets->available)->toBe(0)
        ->and($buckets->spent)->toBe(70);

    // A consumed reversal replays onto its single dispute without new rows.
    $movementsBefore = RewardPointMovement::query()->count();

    $rerun = ClawBackAwardPointsAction::run($award, 'user', $staff, 'staff', 'source-error', CarbonImmutable::parse('2026-06-20T00:00:00Z'));

    expect($rerun['dispute_id'])->toBe((string) $dispute->getKey())
        ->and($rerun['clawed_points'])->toBe(0)
        ->and($rerun['consumed_points'])->toBe(30)
        ->and(RewardPointMovement::query()->count())->toBe($movementsBefore)
        ->and(RewardAdjustment::query()->where('award_id', $award->getKey())->count())->toBe(1);
});

it('D2 voids attested reservations atomically and never resurrects', function () {
    $setup = correctionSetup('corr-d2v');
    [, , , , $memberId, , $staff] = $setup;
    $award = correctionFulfill($setup, 'src-d2v-1', 100);

    ReserveRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 100, 'rsv:d2v-1', 'hold', CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    $resolver = new FakeRewardReservationResolver(['rsv:d2v-1']);
    $reversed = correctionReverse($award->refresh(), $staff, '2026-06-20T00:00:00Z', $resolver);

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed)
        ->and(RewardAdjustment::query()->where('award_id', $award->getKey())->count())->toBe(0)
        ->and($resolver->readKeys())->toBe(['rsv:d2v-1']);

    $void = RewardPointMovement::query()->where('reservation_key', 'rsv:d2v-1')->where('kind', 'release')->firstOrFail();

    expect($void->reason)->toBe('reversal-void:source-error')
        ->and($void->linked_movement_id)->not->toBeNull();

    expect(RewardPointLot::query()->where('award_id', $award->getKey())->firstOrFail()->status)->toBe(RewardPointLotStatus::Depleted);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($buckets->available)->toBe(0)
        ->and($buckets->reserved)->toBe(0)
        ->and($buckets->spent)->toBe(0);

    // A later ordinary release replays onto the void rows instead of freeing
    // value, and a later capture finds its reservation resolved.
    $movementsBefore = RewardPointMovement::query()->count();

    $replayed = ReleaseRewardPointsAction::run('rsv:d2v-1', 'late-cancel', CarbonImmutable::parse('2026-06-22T00:00:00Z'));

    expect($replayed)->toHaveCount(1)
        ->and((string) $replayed[0]->getKey())->toBe((string) $void->getKey())
        ->and(RewardPointMovement::query()->count())->toBe($movementsBefore);

    expect(fn () => DebitRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 100, 'spend:d2v-cap', 'capture', 'rsv:d2v-1', CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'already resolved');

    expect(fn () => DebitRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 1, 'spend:d2v-direct', 'spend', null, CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Insufficient available');

    $settled = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-23T00:00:00Z'));

    expect($settled->available)->toBe(0)
        ->and($settled->reserved)->toBe(0);
});

it('D2 blocks reversal on unknown reservations without partial effects', function () {
    $setup = correctionSetup('corr-d2u');
    [, , , , $memberId, , $staff] = $setup;
    $award = correctionFulfill($setup, 'src-d2u-1', 100);

    ReserveRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 100, 'rsv:d2u-1', 'hold', CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    // Genuinely unknown external status: the hold was handed to external
    // fulfillment. (An unmarked hold is known-unsubmitted under the real
    // resolver and voids cleanly; see the attested cases above.)
    MarkRewardReservationSubmittedAction::run('rsv:d2u-1', RewardPointReservationSubmission::STATUS_SENDING, 'ext-d2u-1');

    $entriesBefore = $award->entries()->count();
    $movementsBefore = RewardPointMovement::query()->count();

    expect(fn () => TransitionRewardAwardAction::run($award, RewardAwardStatus::Reversed, 'user', $staff, 'staff', 'source-error', null, null, CarbonImmutable::parse('2026-06-20T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unknown external status');

    expect($award->refresh()->status)->toBe(RewardAwardStatus::Fulfilled)
        ->and($award->reversed_at)->toBeNull()
        ->and($award->entries()->count())->toBe($entriesBefore)
        ->and(RewardPointMovement::query()->count())->toBe($movementsBefore)
        ->and(RewardAdjustment::query()->where('award_id', $award->getKey())->count())->toBe(0)
        ->and(RewardPointLot::query()->where('award_id', $award->getKey())->firstOrFail()->status)->toBe(RewardPointLotStatus::Open);

    $blocked = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-20T12:00:00Z'));

    expect($blocked->reserved)->toBe(100)
        ->and($blocked->available)->toBe(0);

    // The blocked correction converges through the ordinary path: release the
    // hold, then reverse with no attestation and no dispute.
    ReleaseRewardPointsAction::run('rsv:d2u-1', 'order-cancelled', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    $reversed = TransitionRewardAwardAction::run($award->refresh(), RewardAwardStatus::Reversed, 'user', $staff, 'staff', 'source-error', null, null, CarbonImmutable::parse('2026-06-22T00:00:00Z'));

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed)
        ->and(RewardAdjustment::query()->where('award_id', $award->getKey())->count())->toBe(0);

    $settled = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-23T00:00:00Z'));

    expect($settled->available)->toBe(0)
        ->and($settled->reserved)->toBe(0);
});

it('D2 disputes captured reservations as confirmed consumption', function () {
    $setup = correctionSetup('corr-d2c');
    [, , , , $memberId, , $staff] = $setup;
    $award = correctionFulfill($setup, 'src-d2c-1', 100);

    ReserveRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 100, 'rsv:d2c-1', 'hold', CarbonImmutable::parse('2026-06-19T00:00:00Z'));
    DebitRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 100, 'spend:d2c-cap', 'capture', 'rsv:d2c-1', CarbonImmutable::parse('2026-06-19T12:00:00Z'));

    $reversed = correctionReverse($award->refresh(), $staff);

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed);

    $dispute = RewardAdjustment::query()->where('award_id', $award->getKey())->firstOrFail();

    expect($dispute->amount_snapshot['consumed_points'])->toBe(100)
        ->and($dispute->amount_snapshot['clawed_points'])->toBe(0);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($buckets->available)->toBe(0)
        ->and($buckets->spent)->toBe(100);
});

it('D2 voids only the reversed award pieces of a cross-award reservation', function () {
    $setup = correctionSetup('corr-d2x');
    [, , , , $memberId, , $staff] = $setup;

    $awardA = correctionFulfill($setup, 'src-d2x-a', 100, null, '2026-06-18T10:00:00Z');
    $awardB = correctionFulfill($setup, 'src-d2x-b', 100, null, '2026-06-18T11:00:00Z');

    ReserveRewardPointsAction::run('user', $memberId, $awardA->family_key, 'pts', 150, 'rsv:d2x-1', 'hold', CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    $resolver = new FakeRewardReservationResolver(['rsv:d2x-1']);
    $reversedA = correctionReverse($awardA->refresh(), $staff, '2026-06-20T00:00:00Z', $resolver);

    expect($reversedA->status)->toBe(RewardAwardStatus::Reversed)
        ->and(RewardAdjustment::query()->where('award_id', $awardA->getKey())->count())->toBe(0)
        ->and($awardB->refresh()->status)->toBe(RewardAwardStatus::Fulfilled);

    $voids = RewardPointMovement::query()->where('reservation_key', 'rsv:d2x-1')->where('reason', 'like', 'reversal-void:%')->get();

    expect($voids)->toHaveCount(1)
        ->and((int) $voids->sum('delta'))->toBe(100);

    $mid = RewardPointLedger::balancesFor('user', $memberId, $awardA->family_key, 'pts', CarbonImmutable::parse('2026-06-20T12:00:00Z'));

    expect($mid->available)->toBe(50)
        ->and($mid->reserved)->toBe(50);

    // The live piece still resolves ordinarily; the voided piece never comes
    // back and the full-amount capture is truthfully refused.
    ReleaseRewardPointsAction::run('rsv:d2x-1', 'order-cancelled', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    $released = RewardPointLedger::balancesFor('user', $memberId, $awardA->family_key, 'pts', CarbonImmutable::parse('2026-06-21T12:00:00Z'));

    expect($released->available)->toBe(100)
        ->and($released->reserved)->toBe(0);

    expect(fn () => DebitRewardPointsAction::run('user', $memberId, $awardA->family_key, 'pts', 150, 'spend:d2x-cap', 'capture', 'rsv:d2x-1', CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'already resolved');

    expect(RewardPointLot::query()->where('award_id', $awardA->getKey())->firstOrFail()->status)->toBe(RewardPointLotStatus::Depleted);
});

it('D2 refuses spendable refunds from reversed lots but keeps live portions', function () {
    $setup = correctionSetup('corr-d2r');
    [, , , , $memberId, , $staff] = $setup;

    $awardA = correctionFulfill($setup, 'src-d2r-a', 100, '2026-07-01T00:00:00Z');
    $awardB = correctionFulfill($setup, 'src-d2r-b', 100, '2026-09-01T00:00:00Z');

    DebitRewardPointsAction::run('user', $memberId, $awardA->family_key, 'pts', 120, 'spend:d2r-1', 'spend', null, CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    $reversedA = correctionReverse($awardA->refresh(), $staff);

    expect($reversedA->status)->toBe(RewardAwardStatus::Reversed);

    $disputeA = RewardAdjustment::query()->where('award_id', $awardA->getKey())->firstOrFail();

    expect($disputeA->amount_snapshot['consumed_points'])->toBe(100)
        ->and($disputeA->amount_snapshot['clawed_points'])->toBe(0);

    // The live award's debited portion still refunds in full.
    $refunds = RefundRewardPointsAction::run('refund:d2r-1', 'spend:d2r-1', 20, 'live-portion', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($refunds)->toHaveCount(1)
        ->and(RewardPointLot::query()->where('award_id', $awardA->getKey())->count())->toBe(1)
        ->and(RewardPointLot::query()->where('award_id', $awardB->getKey())->count())->toBe(2);

    $mid = RewardPointLedger::balancesFor('user', $memberId, $awardA->family_key, 'pts', CarbonImmutable::parse('2026-06-21T12:00:00Z'));

    expect($mid->available)->toBe(100);

    // The corrected-credit remainder refuses instead of resurrecting.
    $lotsBefore = RewardPointLot::query()->count();

    expect(fn () => RefundRewardPointsAction::run('refund:d2r-2', 'spend:d2r-1', 10, 'corrected-remainder', CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'reversed award');

    expect(RewardPointLot::query()->count())->toBe($lotsBefore);

    // Reversing the live award nets its refunded consumption to zero.
    $reversedB = correctionReverse($awardB->refresh(), $staff);

    expect($reversedB->status)->toBe(RewardAwardStatus::Reversed)
        ->and(RewardAdjustment::query()->where('award_id', $awardB->getKey())->count())->toBe(0);

    $final = RewardPointLedger::balancesFor('user', $memberId, $awardA->family_key, 'pts', CarbonImmutable::parse('2026-06-23T00:00:00Z'));

    expect($final->available)->toBe(0)
        ->and($final->spent)->toBe(120);
});

it('D2 voids expired reservations into expiry without resurrection', function () {
    $setup = correctionSetup('corr-d2e');
    [, , , , $memberId, , $staff] = $setup;
    $award = correctionFulfill($setup, 'src-d2e-1', 100, '2026-07-01T00:00:00Z');

    ReserveRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 100, 'rsv:d2e-1', 'hold', CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    $resolver = new FakeRewardReservationResolver(['rsv:d2e-1']);
    $reversed = correctionReverse($award->refresh(), $staff, '2026-07-02T00:00:00Z', $resolver);

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed)
        ->and(RewardAdjustment::query()->where('award_id', $award->getKey())->count())->toBe(0);

    $void = RewardPointMovement::query()->where('reservation_key', 'rsv:d2e-1')->where('kind', 'expire')->firstOrFail();

    expect($void->reason)->toBe('reversal-void:source-error')
        ->and((int) $void->delta)->toBe(100);

    $lot = RewardPointLot::query()->where('award_id', $award->getKey())->firstOrFail();

    expect($lot->status)->toBe(RewardPointLotStatus::Open)
        ->and($lot->expired_at)->toBeNull();

    $buckets = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-07-03T00:00:00Z'));

    expect($buckets->expired)->toBe(100)
        ->and($buckets->available)->toBe(0)
        ->and($buckets->reserved)->toBe(0);

    // The later expiry job stamps the lot without double-expiring the void,
    // and a later release replays onto the void rows.
    $expired = ExpireRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-07-04T00:00:00Z'));

    expect($expired)->toBe([])
        ->and(RewardPointMovement::query()->where('kind', 'expire')->count())->toBe(1)
        ->and($lot->refresh()->status)->toBe(RewardPointLotStatus::Expired);

    $movementsBefore = RewardPointMovement::query()->count();

    $replayed = ReleaseRewardPointsAction::run('rsv:d2e-1', 'late-cancel', CarbonImmutable::parse('2026-07-05T00:00:00Z'));

    expect($replayed)->toHaveCount(1)
        ->and(RewardPointMovement::query()->count())->toBe($movementsBefore);
});

it('D2 records voided holds in the consumed dispute evidence', function () {
    $setup = correctionSetup('corr-d2m');
    [, , , , $memberId, , $staff] = $setup;
    $award = correctionFulfill($setup, 'src-d2m-1', 100);

    ReserveRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 30, 'rsv:d2m-1', 'hold', CarbonImmutable::parse('2026-06-19T00:00:00Z'));
    DebitRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 20, 'spend:d2m-1', 'spend', null, CarbonImmutable::parse('2026-06-19T12:00:00Z'));

    $resolver = new FakeRewardReservationResolver(['rsv:d2m-1']);
    $reversed = correctionReverse($award->refresh(), $staff, '2026-06-20T00:00:00Z', $resolver);

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed);

    $dispute = RewardAdjustment::query()->where('award_id', $award->getKey())->firstOrFail();

    expect($dispute->amount_snapshot['consumed_points'])->toBe(20)
        ->and($dispute->amount_snapshot['clawed_points'])->toBe(80)
        ->and($dispute->amount_snapshot['voided_reservations'])->toBe(['rsv:d2m-1'])
        ->and($dispute->amount_snapshot['voided_points'])->toBe(30);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($buckets->available)->toBe(0)
        ->and($buckets->reserved)->toBe(0)
        ->and($buckets->spent)->toBe(20);
});

it('D2 keeps milestone invalidation atomic with attested reservations', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled(
        'corr-d2mi', RewardProgramMode::Points,
        ['milestones' => [['key' => 'correction-one', 'target_count' => 1, 'window_days' => 30, 'reward' => ['type' => 'points', 'points' => 25, 'unit' => 'pts']]]],
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-d2mi-1', $memberId, '2026-06-10T00:00:00Z'));

    (new RecordRewardMilestoneContributionAction($reader))->handle(
        'user', $memberId, $program->family_key, 'correction-one', 1, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        'src-d2mi-1', 'user', $memberId, RewardBeneficiaryRole::Participant,
        RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        (string) $program->getKey(),
    );

    $award = RewardAward::query()->where('source_business_key', 'like', 'milestone:%')->firstOrFail();
    $staff = RewardLedgerFixtures::staffId();

    expect($award->status)->toBe(RewardAwardStatus::Approved);

    (new TransitionRewardAwardAction(null, $reader))->handle(
        $award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill',
        null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    ReserveRewardPointsAction::run('user', $memberId, $program->family_key, 'pts', 25, 'rsv:d2mi-1', 'hold', CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    // Genuinely unknown external status: the hold was handed to external
    // fulfillment, so no attestation can void it.
    MarkRewardReservationSubmittedAction::run('rsv:d2mi-1', RewardPointReservationSubmission::STATUS_SENDING, 'ext-d2mi-1');

    $contribution = RewardMilestoneContribution::query()->where('source_business_key', 'src-d2mi-1')->firstOrFail();

    // Without attestation the whole correction refuses: the revoke, the
    // recount, and the reversal all roll back together.
    expect(fn () => InvalidateRewardMilestoneContributionAction::run($contribution, 'user', $staff, 'staff', 'source-cancelled', CarbonImmutable::parse('2026-06-20T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unknown external status');

    expect($contribution->refresh()->revoked_at)->toBeNull()
        ->and(RewardMilestoneCycle::query()->firstOrFail()->status)->toBe(RewardMilestoneStatus::Achieved)
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Fulfilled);

    $resolver = new FakeRewardReservationResolver(['rsv:d2mi-1']);

    $cycle = (new InvalidateRewardMilestoneContributionAction($resolver))->handle(
        $contribution, 'user', $staff, 'staff', 'source-cancelled', CarbonImmutable::parse('2026-06-20T00:00:00Z'),
    );

    expect($cycle->status)->toBe(RewardMilestoneStatus::Invalidated)
        ->and($cycle->current_count)->toBe(0)
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Reversed)
        ->and(RewardAdjustment::query()->where('award_id', $award->getKey())->count())->toBe(0);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($buckets->available)->toBe(0)
        ->and($buckets->reserved)->toBe(0);
});

it('D1 keeps an unconsumed reversal free of disputes and reservations consults', function () {
    $setup = correctionSetup('corr-d1n');
    [, , , , $memberId, , $staff] = $setup;
    $award = correctionFulfill($setup, 'src-d1n-1', 100);

    $resolver = new FakeRewardReservationResolver;
    $reversed = correctionReverse($award->refresh(), $staff, '2026-06-20T00:00:00Z', $resolver);

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed)
        ->and(RewardAdjustment::query()->where('award_id', $award->getKey())->count())->toBe(0)
        ->and($resolver->readKeys())->toBe([]);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($buckets->available)->toBe(0);

    expect((int) RewardPointMovement::query()->where('kind', 'adjust')->sum('delta'))->toBe(-100);
});
