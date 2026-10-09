<?php

use App\Actions\Rewards\AdvanceRewardAppealAction;
use App\Actions\Rewards\AppendRewardAdjustmentAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\OpenRewardAppealAction;
use App\Enums\Rewards\RewardAdjustmentKind;
use App\Enums\Rewards\RewardAdjustmentStatus;
use App\Enums\Rewards\RewardAppealStatus;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\RewardAdjustment;
use App\Models\RewardAppeal;
use App\Support\Rewards\RewardOperationKeys;
use Carbon\CarbonImmutable;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

function appealHarness(string $source = 'src-appeal-1', array $factOverrides = []): array
{
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled('ledger-appeal');

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, $factOverrides));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    return [$result->award->refresh(), $memberId, $reader];
}

it('opens appeals inside the window and refuses late, foreign, and duplicate appeals', function () {
    [$award, $memberId] = appealHarness();

    $opened = OpenRewardAppealAction::run($award, 'user', $memberId, 'member', 'amount-looks-wrong', ['note' => 'expected-more'], CarbonImmutable::parse('2026-06-20T00:00:00Z'), 30);

    expect($opened->status)->toBe(RewardAppealStatus::Open)
        ->and($opened->decisions()->count())->toBe(1)
        ->and($opened->window_ends_at)->not->toBeNull();

    expect(fn () => OpenRewardAppealAction::run($award, 'user', $memberId, 'member', 'second-try', null, CarbonImmutable::parse('2026-06-21T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'already runs');

    expect(fn () => OpenRewardAppealAction::run($award, 'user', RewardLedgerFixtures::staffId(), 'member', 'foreign', null, CarbonImmutable::parse('2026-06-21T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'their own awards');

    [$lateAward, $lateMember] = appealHarness('src-appeal-late-1');

    expect(fn () => OpenRewardAppealAction::run($lateAward, 'user', $lateMember, 'member', 'too-late', null, CarbonImmutable::parse('2026-08-20T00:00:00Z'), 30))
        ->toThrow(InvalidArgumentException::class, 'window has closed');

    expect(RewardAppeal::query()->count())->toBe(1);
});

it('reviews appeals with separated identities and appends every decision', function () {
    [$award, $memberId] = appealHarness('src-appeal-rev-1');

    $opened = OpenRewardAppealAction::run($award, 'user', $memberId, 'member', 'please-recheck', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    $reviewer = RewardLedgerFixtures::staffId();

    expect(fn () => AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $memberId, 'staff', 'self-review', null, CarbonImmutable::parse('2026-06-21T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'opener cannot decide');

    expect(fn () => AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $reviewer, 'member', 'member-review', null, CarbonImmutable::parse('2026-06-21T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'staff/admin');

    $inReview = AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $reviewer, 'staff', 'taking-case', null, CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($inReview->status)->toBe(RewardAppealStatus::InReview)
        ->and($inReview->in_review_at)->not->toBeNull();

    $upheld = AdvanceRewardAppealAction::run($opened, RewardAppealStatus::Upheld, 'user', $reviewer, 'staff', 'decision-correct', ['checked' => true], CarbonImmutable::parse('2026-06-22T00:00:00Z'));

    expect($upheld->status)->toBe(RewardAppealStatus::Upheld)
        ->and($upheld->decisions()->count())->toBe(3)
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Approved);

    $closed = AdvanceRewardAppealAction::run($opened, RewardAppealStatus::Closed, 'user', $reviewer, 'staff', 'done', null, CarbonImmutable::parse('2026-06-23T00:00:00Z'));

    expect($closed->status)->toBe(RewardAppealStatus::Closed)
        ->and($closed->closed_at)->not->toBeNull();

    expect(fn () => AdvanceRewardAppealAction::run($opened, RewardAppealStatus::Open, 'user', $reviewer, 'staff', 'reopen', null, CarbonImmutable::parse('2026-06-24T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Invalid appeal transition');
});

it('overturns rejected awards by appending overrides while the original stands', function () {
    [$seedProgram, , , $seedMember] = RewardLedgerFixtures::seedActiveEnrolled('ledger-appeal-over');

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-appeal-over-1', $seedMember, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['verification' => RewardVerificationLevel::Unverified]));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $seedProgram, RewardComponent::Points, 'user', $seedMember, RewardBeneficiaryRole::Recipient,
        'src-appeal-over-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $award = $result->award->refresh();
    $memberId = $seedMember;

    expect($award->status)->toBe(RewardAwardStatus::Rejected);

    // The source verifies after rejection, so the merit review overturns
    // against now-qualified evidence; published rules are never overridden.
    $reader->seed(RewardLedgerFixtures::fact('src-appeal-over-1', $memberId));

    $opened = OpenRewardAppealAction::run($award, 'user', $memberId, 'member', 'verification-completed', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    $reviewer = RewardLedgerFixtures::staffId();

    AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $reviewer, 'admin', 'taking-case', null, CarbonImmutable::parse('2026-06-21T00:00:00Z'));
    $overturned = (new AdvanceRewardAppealAction($reader))->handle($opened, RewardAppealStatus::Overturned, 'user', $reviewer, 'admin', 'evidence-now-qualified', ['review' => 'verified'], CarbonImmutable::parse('2026-06-22T00:00:00Z'));

    expect($overturned->status)->toBe(RewardAppealStatus::Overturned);

    $award = $award->refresh();

    expect($award->status)->toBe(RewardAwardStatus::Approved)
        ->and($award->entries()->count())->toBe(2);

    $entries = $award->entries()->orderBy('seq')->get();

    expect($entries[0]->to_status)->toBe('rejected')
        ->and($entries[1]->to_status)->toBe('approved')
        ->and($entries[1]->reason)->toStartWith('appeal-overturned:');
});

it('appends idempotent adjustments and resolves them with separated identities', function () {
    [$award, $memberId] = appealHarness('src-appeal-adj-1');

    $staff = RewardLedgerFixtures::staffId();
    $key = RewardOperationKeys::adjustment('adjustment', 'award', (string) $award->getKey(), 'manual-fix-1');

    $first = AppendRewardAdjustmentAction::run(
        RewardAdjustmentKind::Adjustment, (string) $award->getKey(), null, null, $key,
        ['type' => 'points', 'points' => 5, 'unit' => 'pts'],
        'user', $staff, 'staff', 'goodwill-topup', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'),
    );

    $replay = AppendRewardAdjustmentAction::run(
        RewardAdjustmentKind::Adjustment, (string) $award->getKey(), null, null, $key,
        ['type' => 'points', 'points' => 5, 'unit' => 'pts'],
        'user', $staff, 'staff', 'goodwill-topup', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'),
    );

    expect((string) $replay->getKey())->toBe((string) $first->getKey())
        ->and(RewardAdjustment::query()->count())->toBe(1);

    expect(fn () => AppendRewardAdjustmentAction::run(RewardAdjustmentKind::Adjustment, null, null, null, 'op:free-float', null, 'user', $staff, 'staff', 'floating', null, CarbonImmutable::parse('2026-06-20T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'must attach');

    expect(fn () => app(AppendRewardAdjustmentAction::class)->resolve($first, 'user', $staff, 'staff', 'self-resolve', CarbonImmutable::parse('2026-06-21T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'cannot resolve their own');

    $resolver = RewardLedgerFixtures::staffId();
    $resolved = app(AppendRewardAdjustmentAction::class)->resolve($first, 'user', $resolver, 'admin', 'settled', CarbonImmutable::parse('2026-06-21T00:00:00Z'));

    expect($resolved->status)->toBe(RewardAdjustmentStatus::Resolved)
        ->and($resolved->resolved_at)->not->toBeNull();

    expect(fn () => $resolved->forceFill(['reason' => 'rewritten'])->save())
        ->toThrow(LogicException::class, 'immutable evidence');
});
