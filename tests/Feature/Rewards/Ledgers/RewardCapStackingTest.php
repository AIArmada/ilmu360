<?php

use App\Actions\Rewards\CreateRewardProgramAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEvaluationOutcome;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardAward;
use App\Models\RewardCapCounter;
use App\Models\RewardSourceComponentClaim;
use App\Support\Rewards\RewardCapWindows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

function stackingProgram(string $family, array $compatible, ?array $caps, string $memberId): array
{
    $program = CreateRewardProgramAction::run($family, 'Stacking program', RewardProgramMode::Points, 'platform', null, 'stacking-test');

    $rules = RewardLedgerFixtures::rules();
    $rules['stacking'] = ['compatible_with' => $compatible];

    if ($caps !== null) {
        $rules['caps'] = $caps;
    }

    $at = CarbonImmutable::parse('2026-06-01T00:00:00Z');
    $version = PublishRewardProgramVersionAction::run($program, $at, $rules, RewardLedgerFixtures::terms(), null, null, null, $at);

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);
    EnrollRewardMemberAction::run($program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    return [$program->refresh(), $version];
}

it('enforces default cross-family exclusivity per source, component, and role', function () {
    $memberId = (string) Str::uuid();
    [$programA] = stackingProgram('ledger-excl-a', [], null, $memberId);
    [$programB] = stackingProgram('ledger-excl-b', [], null, $memberId);

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-excl-1', $memberId));
    $action = new EvaluateRewardEntitlementAction($reader);
    $decision = CarbonImmutable::parse('2026-06-15T00:00:00Z');

    $first = $action->handle($programA, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-excl-1', $decision);
    $second = $action->handle($programB, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-excl-1', $decision);

    expect($first->outcome)->toBe(RewardEvaluationOutcome::Awarded)
        ->and($second->outcome)->toBe(RewardEvaluationOutcome::Rejected)
        ->and($second->reasons)->toBe(['stacking_conflict'])
        ->and(RewardSourceComponentClaim::query()->count())->toBe(1);
});

it('allows explicit bilateral compatible stacking and refuses one-directional claims', function () {
    $memberId = (string) Str::uuid();
    $stackCaps = [['scope' => 'recipient_program_day', 'limit' => 10]];
    [$programA] = stackingProgram('ledger-comp-a', ['ledger-comp-b'], $stackCaps, $memberId);
    [$programB] = stackingProgram('ledger-comp-b', ['ledger-comp-a'], $stackCaps, $memberId);

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-comp-1', $memberId));
    $action = new EvaluateRewardEntitlementAction($reader);
    $decision = CarbonImmutable::parse('2026-06-15T00:00:00Z');

    $first = $action->handle($programA, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-comp-1', $decision);
    $second = $action->handle($programB, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-comp-1', $decision);

    expect($first->outcome)->toBe(RewardEvaluationOutcome::Awarded)
        ->and($second->outcome)->toBe(RewardEvaluationOutcome::Awarded)
        ->and(RewardSourceComponentClaim::query()->count())->toBe(2);

    $claims = RewardSourceComponentClaim::query()->orderBy('family_key')->get();

    expect($claims[0]->compatible_snapshot)->toBe(['ledger-comp-b'])
        ->and($claims[1]->compatible_snapshot)->toBe(['ledger-comp-a']);

    [$programC] = stackingProgram('ledger-one-c', ['ledger-one-d'], null, $memberId);
    [$programD] = stackingProgram('ledger-one-d', [], null, $memberId);

    $reader->seed(RewardLedgerFixtures::fact('src-one-1', $memberId));

    $third = $action->handle($programC, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-one-1', $decision);
    $fourth = $action->handle($programD, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-one-1', $decision);

    expect($third->outcome)->toBe(RewardEvaluationOutcome::Awarded)
        ->and($fourth->outcome)->toBe(RewardEvaluationOutcome::Rejected)
        ->and($fourth->reasons)->toBe(['stacking_conflict']);
});

it('binds the recipient-source cap across stacked families unless relaxed', function () {
    $memberId = (string) Str::uuid();
    [$programA] = stackingProgram('ledger-rsc-a', ['ledger-rsc-b'], null, $memberId);
    [$programB] = stackingProgram('ledger-rsc-b', ['ledger-rsc-a'], null, $memberId);

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-rsc-1', $memberId));
    $action = new EvaluateRewardEntitlementAction($reader);
    $decision = CarbonImmutable::parse('2026-06-15T00:00:00Z');

    $first = $action->handle($programA, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-rsc-1', $decision);

    expect($first->outcome)->toBe(RewardEvaluationOutcome::Awarded);

    expect(fn () => $action->handle($programB, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-rsc-1', $decision))
        ->toThrow(InvalidArgumentException::class, 'cap_exceeded');
});

it('blocks same-family second beneficiaries on an already-claimed source', function () {
    $memberId = (string) Str::uuid();
    $otherId = (string) Str::uuid();
    [$programA] = stackingProgram('ledger-samef-a', [], null, $memberId);

    $version = $programA->versions()->orderBy('version')->firstOrFail();
    EnrollRewardMemberAction::run($programA, 'user', $otherId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-samef-1', $memberId));
    $action = new EvaluateRewardEntitlementAction($reader);
    $decision = CarbonImmutable::parse('2026-06-15T00:00:00Z');

    $first = $action->handle($programA, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-samef-1', $decision);

    expect($first->outcome)->toBe(RewardEvaluationOutcome::Awarded);

    $reader->seed(RewardLedgerFixtures::fact('src-samef-1', $otherId));

    $second = $action->handle($programA, RewardComponent::Points, 'user', $otherId, RewardBeneficiaryRole::Recipient, 'src-samef-1', $decision);

    expect($second->outcome)->toBe(RewardEvaluationOutcome::Rejected)
        ->and($second->reasons)->toBe(['stacking_conflict']);
});

it('enforces day, total, and cross-program caps without consuming on rejection', function () {
    $memberId = (string) Str::uuid();
    [$program] = stackingProgram('ledger-cap-a', ['ledger-cap-b'], [
        ['scope' => 'recipient_source_component', 'limit' => 1],
        ['scope' => 'recipient_program_day', 'limit' => 1],
        ['scope' => 'program_total', 'limit' => 10],
        ['scope' => 'family_source_component', 'limit' => 1],
    ], $memberId);

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-cap-1', $memberId));
    $reader->seed(RewardLedgerFixtures::fact('src-cap-2', $memberId));

    $action = new EvaluateRewardEntitlementAction($reader);

    $first = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-cap-1', CarbonImmutable::parse('2026-06-15T10:00:00Z'));

    expect($first->outcome)->toBe(RewardEvaluationOutcome::Awarded);

    expect(fn () => $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-cap-2', CarbonImmutable::parse('2026-06-15T11:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'cap_exceeded');

    expect(RewardAward::query()->where('source_business_key', 'src-cap-2')->count())->toBe(0);

    $nextDay = $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-cap-2', CarbonImmutable::parse('2026-06-16T10:00:00Z'));

    expect($nextDay->outcome)->toBe(RewardEvaluationOutcome::Awarded);

    $dayCounters = RewardCapCounter::query()->where('scope', 'recipient_program_day')->get();

    expect($dayCounters)->toHaveCount(2)
        ->and($dayCounters->pluck('window_key')->sort()->values()->all())->toBe(['2026-06-15', '2026-06-16']);

    [$programB] = stackingProgram('ledger-cap-b', ['ledger-cap-a'], [
        ['scope' => 'recipient_source_component', 'limit' => 1],
        ['scope' => 'family_source_component', 'limit' => 1],
    ], $memberId);

    $reader->seed(RewardLedgerFixtures::fact('src-capx-1', $memberId));

    $crossFirst = $action->handle($programB, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-capx-1', CarbonImmutable::parse('2026-06-17T10:00:00Z'));

    $keys = RewardCapWindows::keysFor(
        'family_source_component', '*', (string) $programB->getKey(),
        'user:'.$memberId, 'src-capx-1', 'points', CarbonImmutable::parse('2026-06-17T10:00:00Z'),
    );

    $counter = RewardCapCounter::query()
        ->where('scope', 'family_source_component')
        ->where('family_key', '*')
        ->where('cap_key', $keys['cap_key'])
        ->firstOrFail();

    expect($crossFirst->outcome)->toBe(RewardEvaluationOutcome::Awarded)
        ->and($counter->consumed)->toBe(1)
        ->and($counter->limit_value)->toBe(1);

    expect(fn () => $action->handle($program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient, 'src-capx-1', CarbonImmutable::parse('2026-06-17T11:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'cap_exceeded');
});
