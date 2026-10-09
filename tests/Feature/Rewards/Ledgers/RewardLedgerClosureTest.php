<?php

use App\Actions\Rewards\AdvanceRewardAppealAction;
use App\Actions\Rewards\CreditRewardPointsAction;
use App\Actions\Rewards\DebitRewardPointsAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\InvalidateRewardMilestoneContributionAction;
use App\Actions\Rewards\OpenRewardAppealAction;
use App\Actions\Rewards\RecordRewardMilestoneContributionAction;
use App\Actions\Rewards\RefundRewardPointsAction;
use App\Actions\Rewards\ReserveRewardPointsAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Enums\Rewards\RewardAppealStatus;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardEvaluationOutcome;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardSourceFraudStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\RewardAward;
use App\Models\RewardCapCounter;
use App\Models\RewardMilestoneContribution;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Models\RewardPointOperation;
use App\Models\RewardSourceComponentClaim;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

function closureIdentity(string $suffix = ''): array
{
    return ['user', (string) Str::uuid(), 'closure-'.$suffix.Str::lower(Str::random(4)), 'pts'];
}

it('A binds reservation identity and refuses same-key amount and unit reuse', function () {
    [$type, $recipient, $family, $unit] = closureIdentity('a1');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:cl-a1', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $first = ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 20, 'rsv:cl-a1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $operation = RewardPointOperation::query()->where('kind', 'reserve')->where('operation_key', 'rsv:cl-a1')->firstOrFail();

    expect((string) $operation->recipient_id)->toBe($recipient)
        ->and((int) $operation->points)->toBe(20);

    $replay = ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 20, 'rsv:cl-a1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    expect((string) $replay[0]->getKey())->toBe((string) $first[0]->getKey())
        ->and(RewardPointOperation::query()->count())->toBe(1);

    $movementsBefore = RewardPointMovement::query()->count();

    expect(fn () => ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 25, 'rsv:cl-a1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different amount');

    expect(fn () => ReserveRewardPointsAction::run($type, $recipient, $family, 'other', 20, 'rsv:cl-a1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different recipient');

    expect(RewardPointMovement::query()->count())->toBe($movementsBefore)
        ->and(RewardPointOperation::query()->count())->toBe(1);
});

it('A binds debit identity across families and capture reservations', function () {
    [$type, $recipient, $family, $unit] = closureIdentity('a2');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:cl-a2', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    DebitRewardPointsAction::run($type, $recipient, $family, $unit, 10, 'spend:cl-a2', 'spend', null, CarbonImmutable::parse('2026-06-06T00:00:00Z'));

    $other = (string) Str::uuid();
    CreditRewardPointsAction::run($type, $other, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:cl-a2b', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    expect(fn () => DebitRewardPointsAction::run($type, $recipient, 'other-family', $unit, 10, 'spend:cl-a2', 'spend', null, CarbonImmutable::parse('2026-06-06T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different recipient');

    ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 15, 'rsv:cl-a2cap', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));
    ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 15, 'rsv:cl-a2cap2', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));
    DebitRewardPointsAction::run($type, $recipient, $family, $unit, 15, 'spend:cl-a2cap', 'capture', 'rsv:cl-a2cap', CarbonImmutable::parse('2026-06-07T00:00:00Z'));

    expect(fn () => DebitRewardPointsAction::run($type, $recipient, $family, $unit, 15, 'spend:cl-a2cap', 'capture', 'rsv:cl-a2cap2', CarbonImmutable::parse('2026-06-07T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different terms');

    expect(RewardPointOperation::query()->where('kind', 'debit')->count())->toBe(2);
});

it('A binds refund identity to its linked debit and rolls back failed claims', function () {
    [$type, $recipient, $family, $unit] = closureIdentity('a3');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 60, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:cl-a3', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    DebitRewardPointsAction::run($type, $recipient, $family, $unit, 20, 'spend:cl-a3-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));
    DebitRewardPointsAction::run($type, $recipient, $family, $unit, 20, 'spend:cl-a3-2', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    RefundRewardPointsAction::run('refund:cl-a3', 'spend:cl-a3-1', 10, 'partial', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

    expect(fn () => RefundRewardPointsAction::run('refund:cl-a3', 'spend:cl-a3-2', 10, 'relink', CarbonImmutable::parse('2026-06-06T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'different debit aggregate');

    // A failed allocation (over the unrefunded remainder) rolls its claim
    // back with everything else: no orphan operation row survives.
    expect(fn () => RefundRewardPointsAction::run('refund:cl-a3-fail', 'spend:cl-a3-1', 99, 'over', CarbonImmutable::parse('2026-06-07T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unrefunded debited amount');

    expect(RewardPointOperation::query()->where('kind', 'refund')->count())->toBe(1);

    expect(fn () => ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 999, 'rsv:cl-a3-fail', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Insufficient available');

    expect(RewardPointOperation::query()->where('operation_key', 'rsv:cl-a3-fail')->count())->toBe(0);
});

it('B refuses held approval while the source stays underverified', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('closure-b1');
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-cl-b1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-cl-b1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Held);

    $reader->seed(RewardLedgerFixtures::fact('src-cl-b1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['verification' => RewardVerificationLevel::Unverified]));
    $staff = RewardLedgerFixtures::staffId();
    $entriesBefore = $result->award->entries()->count();

    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'approve', null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'verification_insufficient');

    expect($result->award->refresh()->status)->toBe(RewardAwardStatus::Held)
        ->and($result->award->entries()->count())->toBe($entriesBefore);
});

it('B advances verified held awards without duplicate claims or cap use', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled(
        'closure-b2', RewardProgramMode::Points, [
            'caps' => [['scope' => 'recipient_program_month', 'limit' => 5]],
            'windows' => ['hold_days' => 5],
        ],
    );
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-cl-b2', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-cl-b2', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $claimsBefore = RewardSourceComponentClaim::query()->count();
    $consumedBefore = RewardCapCounter::query()->get()->mapWithKeys(
        fn ($counter): array => [$counter->cap_key.'|'.$counter->window_key => $counter->consumed]
    )->all();

    $staff = RewardLedgerFixtures::staffId();

    (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'approve', null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z'));

    expect($result->award->refresh()->status)->toBe(RewardAwardStatus::Approved)
        ->and(RewardSourceComponentClaim::query()->count())->toBe($claimsBefore);

    $consumedAfter = RewardCapCounter::query()->get()->mapWithKeys(
        fn ($counter): array => [$counter->cap_key.'|'.$counter->window_key => $counter->consumed]
    )->all();

    expect($consumedAfter)->toBe($consumedBefore);

    $entry = $result->award->entries()->reorder('seq', 'desc')->firstOrFail();

    expect($entry->evidence['frozen_qualification']['verdict'] ?? null)->toBe('hold-resolved-by-review');
});

it('B refuses milestone fulfillment after a contributor correction until the hook processes it', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled(
        'closure-b3', RewardProgramMode::Points, ['milestones' => [['key' => 'closing-two', 'target_count' => 2, 'window_days' => 30, 'reward' => ['type' => 'points', 'points' => 25, 'unit' => 'pts']]]],
    );

    $reader = new FakeRewardSourceFactReader;
    $record = function (string $source, string $day) use ($reader, $program, $memberId): array {
        $reader->seed(RewardLedgerFixtures::fact($source, $memberId, "2026-06-{$day}T00:00:00Z"));

        return (new RecordRewardMilestoneContributionAction($reader))->handle(
            'user', $memberId, $program->family_key, 'closing-two', 2, 30,
            RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
            $source, 'user', $memberId, RewardBeneficiaryRole::Participant,
            RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse("2026-06-{$day}T00:00:00Z"),
            (string) $program->getKey(),
        );
    };

    $record('src-cl-b3-1', '10');
    $record('src-cl-b3-2', '11');
    $record('src-cl-b3-3', '12');

    $award = RewardAward::query()->where('source_business_key', 'like', 'milestone:%')->firstOrFail();
    $staff = RewardLedgerFixtures::staffId();

    expect($award->status)->toBe(RewardAwardStatus::Approved);

    // A contributor cancels after achievement but before fulfillment: the
    // fulfillment refuses even though the hook has not run yet.
    $reader->seed(RewardLedgerFixtures::fact('src-cl-b3-1', $memberId, '2026-06-10T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isCancelled' => true]));

    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle($award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'source_cancelled');

    expect($award->refresh()->status)->toBe(RewardAwardStatus::Approved);

    // The hook revokes the corrected contribution; the above-target cycle
    // stays achieved and fulfillment succeeds on the remaining evidence.
    $bad = RewardMilestoneContribution::query()->where('source_business_key', 'src-cl-b3-1')->firstOrFail();
    InvalidateRewardMilestoneContributionAction::run($bad, 'user', $staff, 'staff', 'source-cancelled', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

    $fulfilled = (new TransitionRewardAwardAction(null, $reader))->handle($award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled)
        ->and(RewardPointLot::query()->where('award_id', $award->getKey())->count())->toBe(1);
});

it('B refuses milestone fulfillment when a contributor becomes unknown', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled(
        'closure-b4', RewardProgramMode::Points, ['milestones' => [['key' => 'closing-pair', 'target_count' => 2, 'window_days' => 30, 'reward' => ['type' => 'points', 'points' => 25, 'unit' => 'pts']]]],
    );

    $reader = new FakeRewardSourceFactReader;

    foreach (['src-cl-b4-1' => '10', 'src-cl-b4-2' => '11'] as $source => $day) {
        $reader->seed(RewardLedgerFixtures::fact($source, $memberId, "2026-06-{$day}T00:00:00Z"));

        (new RecordRewardMilestoneContributionAction($reader))->handle(
            'user', $memberId, $program->family_key, 'closing-pair', 2, 30,
            RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
            $source, 'user', $memberId, RewardBeneficiaryRole::Participant,
            RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse("2026-06-{$day}T00:00:00Z"),
            (string) $program->getKey(),
        );
    }

    $award = RewardAward::query()->where('source_business_key', 'like', 'milestone:%')->firstOrFail();
    $staff = RewardLedgerFixtures::staffId();

    $nullReader = new FakeRewardSourceFactReader;
    $nullReader->seed(RewardLedgerFixtures::fact('src-cl-b4-1', $memberId, '2026-06-10T00:00:00Z'));

    expect(fn () => (new TransitionRewardAwardAction(null, $nullReader))->handle($award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unknown_source');

    expect($award->refresh()->status)->toBe(RewardAwardStatus::Approved)
        ->and(RewardPointLot::query()->where('award_id', $award->getKey())->count())->toBe(0);
});

it('B clears only targeted merit gates on overturn and never published rules', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('closure-b5');
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-cl-b5', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['verification' => RewardVerificationLevel::Unverified]));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-cl-b5', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Rejected);

    // Verified but fraud-suspect: overturn without the targeted gate refuses.
    $reader->seed(RewardLedgerFixtures::fact('src-cl-b5', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['fraudStatus' => RewardSourceFraudStatus::Suspect]));

    $opened = OpenRewardAppealAction::run($result->award, 'user', $memberId, 'member', 'please', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    $reviewer = RewardLedgerFixtures::staffId();

    AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $reviewer, 'admin', 'taking', null, CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect(fn () => (new AdvanceRewardAppealAction($reader))->handle($opened, RewardAppealStatus::Overturned, 'user', $reviewer, 'admin', 'overturn', null, CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'fraud_suspect_held');

    expect(fn () => (new AdvanceRewardAppealAction($reader))->handle($opened, RewardAppealStatus::Overturned, 'user', $reviewer, 'admin', 'overturn', ['merit_override' => ['verification_insufficient']], CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unknown merit-override gate');

    $overturned = (new AdvanceRewardAppealAction($reader))->handle($opened, RewardAppealStatus::Overturned, 'user', $reviewer, 'admin', 'overturn', ['merit_override' => ['fraud_suspect_held']], CarbonImmutable::parse('2026-06-22T00:00:00Z'));

    expect($overturned->status)->toBe(RewardAppealStatus::Overturned)
        ->and($result->award->refresh()->status)->toBe(RewardAwardStatus::Approved)
        ->and(RewardSourceComponentClaim::query()->count())->toBe(1);

    // Merit evidence on a non-overturn path is refused, never silently kept.
    [$program2, , , $member2] = RewardLedgerFixtures::seedActiveEnrolled('closure-b5b');
    $reader2 = new FakeRewardSourceFactReader;
    $reader2->seed(RewardLedgerFixtures::fact('src-cl-b5b', $member2, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));

    $held = (new EvaluateRewardEntitlementAction($reader2))->handle(
        $program2, RewardComponent::Points, 'user', $member2, RewardBeneficiaryRole::Recipient,
        'src-cl-b5b', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect(fn () => (new TransitionRewardAwardAction(null, $reader2))->handle($held->award, RewardAwardStatus::Approved, 'user', $reviewer, 'staff', 'approve', ['merit_override' => ['fraud_suspect_held']], null, CarbonImmutable::parse('2026-06-17T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'only to appeal overturns');
});

it('B refuses overturns that would bypass frozen outcome rules', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('closure-b6');
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-cl-b6', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventSave));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-cl-b6', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Rejected);

    $opened = OpenRewardAppealAction::run($result->award, 'user', $memberId, 'member', 'please', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    $reviewer = RewardLedgerFixtures::staffId();

    AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $reviewer, 'admin', 'taking', null, CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect(fn () => (new AdvanceRewardAppealAction($reader))->handle($opened, RewardAppealStatus::Overturned, 'user', $reviewer, 'admin', 'overturn', null, CarbonImmutable::parse('2026-06-22T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'outcome_not_allowlisted');

    expect($result->award->refresh()->status)->toBe(RewardAwardStatus::Rejected)
        ->and(RewardSourceComponentClaim::query()->count())->toBe(0);
});
