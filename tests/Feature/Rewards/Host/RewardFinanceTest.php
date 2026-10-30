<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\RegisterRewardPayeeAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Actions\VerifyRewardPayeeAction;
use AIArmada\Rewards\Contracts\RewardFinancialReadinessChecker;
use AIArmada\Rewards\Contracts\RewardPayoutExecutor;
use AIArmada\Rewards\Contracts\RewardPayoutMethodCatalog;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Support\NullRewardPayoutExecutor;
use AIArmada\Rewards\Support\RewardBudgetLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

it('exposes no cash payout methods in the host', function (): void {
    $catalog = app(RewardPayoutMethodCatalog::class);

    expect(config('rewards.payouts.methods'))->toBe([])
        ->and($catalog->registered())->toBe([])
        ->and($catalog->describe())->toBe([])
        ->and($catalog->isSupported('manual'))->toBeFalse()
        ->and(app(RewardPayoutExecutor::class))->toBeInstanceOf(NullRewardPayoutExecutor::class)
        ->and(config('affiliates.payouts.reward_award_settlement.enabled'))->toBeFalse();
});

it('refuses funded cash activation while payouts are disabled', function (): void {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('finance-disabled', RewardProgramMode::Cash);
    $staff = RewardLedgerFixtures::staffId();

    RecordRewardFundingSourceAction::run('finance-disabled-source', 'MYR', 10000, 'user', $staff, 'staff');
    RecordRewardFundingAction::run($program, 'MYR', 10000, 'finance-disabled-source', 'user', $staff, 'staff');

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);

    expect(fn () => TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active))
        ->toThrow(InvalidArgumentException::class, 'no-supported-payout-processor');

    expect($program->fresh()->status)->toBe(RewardProgramStatus::Ready)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))->toBe([
            'funded' => 10000,
            'reserved' => 0,
            'net_spent' => 0,
            'available' => 10000,
        ]);
});

it('does not treat a verified payee as cash readiness while payouts are disabled', function (): void {
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('finance-payee', RewardProgramMode::Cash);
    $program = RewardLedgerFixtures::forceActive($program);

    $staff = RewardLedgerFixtures::staffId();
    $memberId = (string) Str::uuid();

    RecordRewardFundingSourceAction::run('finance-payee-source', 'MYR', 10000, 'user', $staff, 'staff');
    RecordRewardFundingAction::run($program, 'MYR', 10000, 'finance-payee-source', 'user', $staff, 'staff');

    EnrollRewardMemberAction::run(
        $program,
        'user',
        $memberId,
        $version,
        'member-portal',
        CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('finance-payee-attendance', $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program,
        RewardComponent::Cash,
        'user',
        $memberId,
        RewardBeneficiaryRole::Recipient,
        'finance-payee-attendance',
        CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    RegisterRewardPayeeAction::run(
        'user',
        $memberId,
        'manual',
        'finance-payee-account',
        'Manual handoff',
        'user',
        $memberId,
        'member',
    );

    VerifyRewardPayeeAction::run(
        'user',
        $memberId,
        'manual',
        'finance-payee-account',
        'Manual handoff',
        'user',
        $staff,
        'staff',
    );

    $assessment = app(RewardFinancialReadinessChecker::class)->canApproveFinancial($result->award);

    expect($assessment->ready)->toBeFalse()
        ->and($assessment->blockers)->toContain('payee-processor-unsupported');

    expect(fn () => (new TransitionRewardAwardAction(
        app(RewardFinancialReadinessChecker::class),
        $reader,
    ))->handle(
        $result->award,
        RewardAwardStatus::Approved,
        'user',
        $staff,
        'staff',
        'policy-ok',
    ))->toThrow(InvalidArgumentException::class, 'payee-processor-unsupported');

    expect($result->award->fresh()->status)->toBe(RewardAwardStatus::Held)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['net_spent'])->toBe(0);
});
