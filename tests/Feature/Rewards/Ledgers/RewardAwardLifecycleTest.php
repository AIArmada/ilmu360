<?php

use App\Actions\Rewards\AcknowledgeRewardRecognitionAction;
use App\Actions\Rewards\DebitRewardPointsAction;
use App\Actions\Rewards\DismissRewardFraudAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Enums\Rewards\RewardAdjustmentKind;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBadgeStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardEvaluationOutcome;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardSourceFraudStatus;
use App\Models\RewardAdjustment;
use App\Models\RewardBadgeGrant;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Support\Rewards\RewardPointLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

function lifecycleAward(RewardProgramMode $mode = RewardProgramMode::Points, string $source = 'src-life-1'): array
{
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('ledger-life', $mode);

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId));
    $component = RewardLedgerFixtures::componentFor($mode);

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, $component, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    return [$result->award->refresh(), $memberId, $program, $reader];
}

function heldPointsAward(string $source = 'src-life-held-1'): array
{
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('ledger-lifeh');

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Held);

    return [$result->award->refresh(), $memberId, $program, $reader];
}

function lifecycleApprove($award, $reader, $to, string $staff, string $reason, string $at)
{
    return (new TransitionRewardAwardAction(null, $reader))->handle(
        $award, $to, 'user', $staff, 'staff', $reason, null, null, CarbonImmutable::parse($at),
    );
}

it('moves held awards through review, approval, and fulfillment with stamped entries', function () {
    [$award, , , $reader] = heldPointsAward();

    $staff = RewardLedgerFixtures::staffId();

    $pending = TransitionRewardAwardAction::run($award, RewardAwardStatus::PendingReview, 'user', $staff, 'staff', 'needs-review', null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    expect($pending->status)->toBe(RewardAwardStatus::PendingReview)
        ->and($pending->pending_review_at)->not->toBeNull()
        ->and($pending->held_at)->not->toBeNull();

    $approved = lifecycleApprove($award, $reader, RewardAwardStatus::Approved, $staff, 'verified-ok', '2026-06-17T00:00:00Z');

    expect($approved->status)->toBe(RewardAwardStatus::Approved)
        ->and($approved->approved_at)->not->toBeNull();

    $fulfilled = lifecycleApprove($award, $reader, RewardAwardStatus::Fulfilled, $staff, 'fulfill-ok', '2026-06-18T00:00:00Z');

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled)
        ->and($fulfilled->fulfilled_at)->not->toBeNull()
        ->and($fulfilled->entries()->count())->toBe(4);

    expect(RewardPointLot::query()->where('award_id', $award->getKey())->count())->toBe(1)
        ->and(RewardPointMovement::query()->where('award_id', $award->getKey())->count())->toBe(1);

    $entries = $fulfilled->entries()->orderBy('seq')->get();

    expect($entries->pluck('to_status')->all())->toBe(['held', 'pending_review', 'approved', 'fulfilled'])
        ->and($entries->pluck('seq')->all())->toBe([1, 2, 3, 4]);
});

it('prohibits caller own-approval and member approval wherever identities are supplied', function () {
    [$award, $memberId, , $reader] = heldPointsAward('src-life-own-1');

    expect(fn () => TransitionRewardAwardAction::run($award, RewardAwardStatus::Approved, 'user', $memberId, 'staff', 'self-approve', null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'own-approval');

    expect(fn () => TransitionRewardAwardAction::run($award, RewardAwardStatus::Approved, 'user', RewardLedgerFixtures::staffId(), 'member', 'member-approve', null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'staff/admin');

    $staffApprover = RewardLedgerFixtures::staffId();
    lifecycleApprove($award, $reader, RewardAwardStatus::Approved, $staffApprover, 'approve-first', '2026-06-17T00:00:00Z');

    expect(fn () => TransitionRewardAwardAction::run($award, RewardAwardStatus::Fulfilled, 'system', null, 'system', 'system-fulfill', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'staff/admin');

    expect($award->refresh()->status)->toBe(RewardAwardStatus::Approved);
});

it('rejects invalid transitions and never rewrites stamped timestamps', function () {
    [$award] = lifecycleAward();

    expect(fn () => TransitionRewardAwardAction::run($award, RewardAwardStatus::Held, 'user', RewardLedgerFixtures::staffId(), 'staff', 'backwards', null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Invalid award transition');

    expect(fn () => $award->forceFill(['amount_snapshot' => ['type' => 'points', 'points' => 999, 'unit' => 'pts']])->save())
        ->toThrow(LogicException::class, 'frozen provenance');

    $award->refresh();

    expect(fn () => $award->forceFill(['approved_at' => CarbonImmutable::parse('2026-08-01T00:00:00Z')])->save())
        ->toThrow(LogicException::class, 'immutable transition timestamp');
});

it('refuses cash and benefit approval and fulfillment while unready', function () {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('ledger-lcash', RewardProgramMode::Cash);
    $program = RewardLedgerFixtures::forceActive($program);

    $memberId = (string) Str::uuid();
    $version = $program->versions()->orderBy('version')->firstOrFail();
    EnrollRewardMemberAction::run($program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-lcash-1', $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Cash, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-lcash-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $staff = RewardLedgerFixtures::staffId();

    expect(fn () => lifecycleApprove($result->award, $reader, RewardAwardStatus::Approved, $staff, 'policy-ok', '2026-06-17T00:00:00Z'))
        ->toThrow(InvalidArgumentException::class, 'funded financial readiness');

    expect($result->award->refresh()->status)->toBe(RewardAwardStatus::Held)
        ->and($result->award->approved_at)->toBeNull();

    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'pay-now', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Invalid award transition');
});

it('acknowledges recognition once and only by the recipient', function () {
    [$award, $memberId] = lifecycleAward(RewardProgramMode::Recognition, 'src-life-rec-1');

    $other = RewardLedgerFixtures::staffId();

    expect(fn () => AcknowledgeRewardRecognitionAction::run($award, $other, CarbonImmutable::parse('2026-06-19T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Only the recipient');

    $acked = AcknowledgeRewardRecognitionAction::run($award, $memberId, CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    expect($acked->acknowledged_at)->not->toBeNull();

    $again = AcknowledgeRewardRecognitionAction::run($award, $memberId, CarbonImmutable::parse('2026-06-20T00:00:00Z'));

    expect((string) $again->acknowledged_at)->toBe((string) $acked->acknowledged_at);

    [$pointsAward] = lifecycleAward(RewardProgramMode::Points, 'src-life-rec-2');

    expect(fn () => AcknowledgeRewardRecognitionAction::run($pointsAward, $memberId))
        ->toThrow(InvalidArgumentException::class, 'Only recognition awards');
});

it('grants badges on badge fulfillment and claws back unspent points on reversal', function () {
    [$award, , , $badgeReader] = lifecycleAward(RewardProgramMode::Badges, 'src-life-badge-1');

    $staff = RewardLedgerFixtures::staffId();
    $fulfilled = lifecycleApprove($award, $badgeReader, RewardAwardStatus::Fulfilled, $staff, 'grant-badge', '2026-06-18T00:00:00Z');

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);

    $grant = RewardBadgeGrant::query()->where('award_id', $award->getKey())->firstOrFail();

    expect($grant->status)->toBe(RewardBadgeStatus::Granted)
        ->and($grant->badge_key)->toBe('first-share');

    [$pointsAward, , , $pointsReader] = lifecycleAward(RewardProgramMode::Points, 'src-life-rev-1');

    lifecycleApprove($pointsAward, $pointsReader, RewardAwardStatus::Fulfilled, $staff, 'fulfill', '2026-06-18T00:00:00Z');

    $reversed = TransitionRewardAwardAction::run($pointsAward, RewardAwardStatus::Reversed, 'user', $staff, 'staff', 'source-error', null, null, CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed)
        ->and($reversed->reversed_at)->not->toBeNull();

    $balances = RewardPointLedger::balancesFor('user', (string) $pointsAward->recipient_id, $pointsAward->family_key, 'pts', CarbonImmutable::parse('2026-06-20T00:00:00Z'));

    expect($balances->available)->toBe(0)
        ->and(RewardAdjustment::query()->where('award_id', $pointsAward->getKey())->count())->toBe(0);
});

it('opens a dispute instead of restoring consumed value on reversal', function () {
    [$award, $memberId, , $reader] = lifecycleAward(RewardProgramMode::Points, 'src-life-cons-1');

    $staff = RewardLedgerFixtures::staffId();
    lifecycleApprove($award, $reader, RewardAwardStatus::Fulfilled, $staff, 'fulfill', '2026-06-18T00:00:00Z');

    DebitRewardPointsAction::run('user', $memberId, $award->family_key, 'pts', 6, 'spend:life-cons-1', 'member-spend', null, CarbonImmutable::parse('2026-06-18T12:00:00Z'));

    $reversed = TransitionRewardAwardAction::run($award, RewardAwardStatus::Reversed, 'user', $staff, 'staff', 'source-error', null, null, CarbonImmutable::parse('2026-06-19T00:00:00Z'));

    expect($reversed->status)->toBe(RewardAwardStatus::Reversed);

    $dispute = RewardAdjustment::query()->where('award_id', $award->getKey())->firstOrFail();

    expect($dispute->kind)->toBe(RewardAdjustmentKind::Dispute)
        ->and($dispute->amount_snapshot['consumed_points'])->toBe(6)
        ->and($dispute->amount_snapshot['clawed_points'])->toBe(4);

    $balances = RewardPointLedger::balancesFor('user', $memberId, $award->family_key, 'pts', CarbonImmutable::parse('2026-06-20T00:00:00Z'));

    expect($balances->available)->toBe(0)
        ->and($balances->spent)->toBe(6);
});

it('dismisses fraud back to held without approving', function () {
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('ledger-lfraud');

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-lfraud-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['fraudStatus' => RewardSourceFraudStatus::Suspect]));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-lfraud-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $staff = RewardLedgerFixtures::staffId();

    $pending = TransitionRewardAwardAction::run($result->award, RewardAwardStatus::PendingReview, 'user', $staff, 'staff', 'screen', null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    expect($pending->status)->toBe(RewardAwardStatus::PendingReview);

    $cleared = DismissRewardFraudAction::run($result->award, 'user', $staff, 'staff', 'no-fraud-found', null, 'fraud:lfraud-1', CarbonImmutable::parse('2026-06-17T00:00:00Z'));

    expect($cleared->status)->toBe(RewardAwardStatus::Held)
        ->and($cleared->approved_at)->toBeNull();

    $entry = $cleared->entries()->reorder('seq', 'desc')->firstOrFail();

    expect($entry->reason)->toBe('fraud_cleared:no-fraud-found')
        ->and($entry->source_operation_id)->toBe('fraud:lfraud-1');

    $replay = DismissRewardFraudAction::run($result->award, 'user', $staff, 'staff', 'no-fraud-found', null, 'fraud:lfraud-1', CarbonImmutable::parse('2026-06-17T00:00:00Z'));

    expect($replay->entries()->count())->toBe($cleared->entries()->count());

    $reader->seed(RewardLedgerFixtures::fact('src-lfraud-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['fraudStatus' => RewardSourceFraudStatus::Clear]));

    $approved = lifecycleApprove($result->award, $reader, RewardAwardStatus::Approved, $staff, 'human-approval', '2026-06-18T00:00:00Z');

    expect($approved->status)->toBe(RewardAwardStatus::Approved);
});
