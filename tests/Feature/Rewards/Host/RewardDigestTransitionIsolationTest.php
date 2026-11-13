<?php

declare(strict_types=1);

// Rw0 characterization (H1): gratitude planning/cancellation runs inside
// the package award evaluation/transition transaction. This file records
// the current coupling without changing it: an evaluation tolerates only
// the contract's InvalidArgumentException refusal, while any other digest
// failure aborts the evaluation; the reversal path has no try/catch at all.
//
// Evaluation of a Points award with the standard fixture rules is born
// approved (the later transition to Approved is an idempotent no-op), so
// the approval-path planner call exercised here is the evaluation call.

use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Contracts\RewardGratitudePlanner;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Models\RewardAward;
use App\Models\RewardDigestSlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rewards\Host\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Host\Support\RewardCharacterizationFixtures;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

function rw0PlannerThrowing(Throwable $planFailure, ?Throwable $cancelFailure = null): RewardGratitudePlanner
{
    return new class($planFailure, $cancelFailure) implements RewardGratitudePlanner
    {
        public function __construct(
            private readonly Throwable $planFailure,
            private readonly ?Throwable $cancelFailure,
        ) {}

        public function planFor(string $recipientType, string $recipientId, array $channels, CarbonImmutable $at): void
        {
            throw $this->planFailure;
        }

        public function cancelPendingFor(string $recipientType, string $recipientId, string $reason, CarbonImmutable $at): void
        {
            throw $this->cancelFailure ?? $this->planFailure;
        }
    };
}

it('awards when the planner refuses with the contract exception', function (): void {
    $user = RewardCharacterizationFixtures::digestUser();
    [$program] = RewardCharacterizationFixtures::digestProgram('rw0-h1-refusal', $user);

    $this->app->instance(RewardGratitudePlanner::class, rw0PlannerThrowing(
        new InvalidArgumentException('Gratitude digests require the recipient to keep the trigger enabled.'),
    ));

    [$award] = RewardCharacterizationFixtures::evaluateAward(
        $program, $user, 'rw0-h1-refusal-source', '2026-06-15T10:00:00Z',
    );

    expect($award->status)->toBe(RewardAwardStatus::Approved)
        ->and(RewardDigestSlot::query()->count())->toBe(0);
});

it('aborts the evaluation when the planner fails outside the refusal contract', function (): void {
    $user = RewardCharacterizationFixtures::digestUser();
    [$program] = RewardCharacterizationFixtures::digestProgram('rw0-h1-abort', $user);

    $memberId = (string) $user->getKey();
    $source = 'rw0-h1-abort-source';
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId));

    $this->app->instance(RewardGratitudePlanner::class, rw0PlannerThrowing(
        new RuntimeException('digest infrastructure unavailable'),
    ));

    expect(fn () => (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    ))->toThrow(RuntimeException::class, 'digest infrastructure unavailable');

    // The digest failure participates in the award transaction, so the
    // award itself is rolled back — the current H1 coupling.
    expect(RewardAward::query()->where('source_business_key', $source)->count())->toBe(0)
        ->and(RewardDigestSlot::query()->count())->toBe(0);
});

it('aborts the reversal when cancelPendingFor fails because there is no try/catch', function (): void {
    $user = RewardCharacterizationFixtures::digestUser();
    [$program] = RewardCharacterizationFixtures::digestProgram('rw0-h1-reverse', $user);

    [$award, $reader] = RewardCharacterizationFixtures::approveAward(
        $program, $user, 'rw0-h1-reverse-source', '2026-06-15T10:00:00Z',
    );

    $slot = RewardCharacterizationFixtures::slotFor($user);

    expect($award->status)->toBe(RewardAwardStatus::Approved)
        ->and($slot->status)->toBe(RewardDigestSlot::STATUS_PLANNED);

    $this->app->instance(RewardGratitudePlanner::class, rw0PlannerThrowing(
        new RuntimeException('digest infrastructure unavailable'),
        new RuntimeException('digest cancellation unavailable'),
    ));

    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle(
        $award, RewardAwardStatus::Reversed, 'user', RewardLedgerFixtures::staffId(), 'staff',
        'source-invalid', null, null, CarbonImmutable::parse('2026-06-15T12:00:00Z'),
    ))->toThrow(RuntimeException::class, 'digest cancellation unavailable');

    $award->refresh();

    expect($award->status)->toBe(RewardAwardStatus::Approved)
        ->and($award->reversed_at)->toBeNull()
        ->and($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_PLANNED);
});
