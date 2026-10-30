<?php

declare(strict_types=1);

use AIArmada\Affiliates\Actions\Conversions\RecordAffiliateAward;
use AIArmada\Affiliates\Enums\AffiliateOutcomeDisposition;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateBalance;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\States\Active;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\RegisterRewardPayeeAction;
use AIArmada\Rewards\Actions\RequestRewardPayoutAction;
use AIArmada\Rewards\Actions\VerifyRewardPayeeAction;
use AIArmada\Rewards\Contracts\RewardPayoutExecutor;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardPayoutRequestStatus;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBudgetMovement;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use AIArmada\Rewards\Support\RewardBudgetLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

it('refuses payout requests while host cash is disabled', function (): void {
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('payout-disabled', RewardProgramMode::Cash);
    $program = RewardLedgerFixtures::forceActive($program);

    $staff = RewardLedgerFixtures::staffId();
    $memberId = (string) Str::uuid();

    RecordRewardFundingSourceAction::run('payout-disabled-source', 'MYR', 10000, 'user', $staff, 'staff');
    RecordRewardFundingAction::run($program, 'MYR', 10000, 'payout-disabled-source', 'user', $staff, 'staff');

    EnrollRewardMemberAction::run(
        $program,
        'user',
        $memberId,
        $version,
        'member-portal',
        CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('payout-disabled-attendance', $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program,
        RewardComponent::Cash,
        'user',
        $memberId,
        RewardBeneficiaryRole::Recipient,
        'payout-disabled-attendance',
        CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    RewardAward::query()->whereKey($result->award->getKey())->update([
        'status' => RewardAwardStatus::Approved->value,
        'approved_at' => CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    ]);

    $award = $result->award->refresh();

    RegisterRewardPayeeAction::run(
        'user',
        $memberId,
        'manual',
        'payout-disabled-account',
        'Manual handoff',
        'user',
        $memberId,
        'member',
    );

    $payee = VerifyRewardPayeeAction::run(
        'user',
        $memberId,
        'manual',
        'payout-disabled-account',
        'Manual handoff',
        'user',
        $staff,
        'staff',
    );

    $balancesBefore = RewardBudgetLedger::balances((string) $program->getKey(), 'MYR');
    $movementsBefore = RewardBudgetMovement::query()
        ->orderBy('movement_key')
        ->pluck('movement_key')
        ->all();

    expect(fn () => RequestRewardPayoutAction::run(
        $award,
        $payee,
        'manual',
        'program',
        'user',
        $staff,
        'staff',
    ))->toThrow(InvalidArgumentException::class, 'Payout processor [manual] is not registered.');

    expect(RewardPayoutRequest::query()->count())->toBe(0)
        ->and(AffiliateConversion::query()->count())->toBe(0)
        ->and(AffiliatePayout::query()->count())->toBe(0)
        ->and(RewardBudgetMovement::query()
            ->orderBy('movement_key')
            ->pluck('movement_key')
            ->all())->toBe($movementsBefore)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))->toBe($balancesBefore);
});

it('refuses every executor operation while host cash is disabled', function (string $operation): void {
    $executor = app(RewardPayoutExecutor::class);

    $request = RewardPayoutRequest::factory()->create([
        'status' => RewardPayoutRequestStatus::Approved,
    ]);

    $before = $request->fresh()->getAttributes();
    $movementsBefore = RewardBudgetMovement::query()->count();

    expect(fn () => match ($operation) {
        'submit' => $executor->submit($request),
        'cancel' => $executor->cancel($request),
        'status' => $executor->status($request),
        'settle' => $executor->settle($request, 'paid'),
    })->toThrow(InvalidArgumentException::class, 'Payout execution is disabled');

    expect($request->fresh()->getAttributes())->toBe($before)
        ->and(RewardBudgetMovement::query()->count())->toBe($movementsBefore);
})->with(['submit', 'cancel', 'status', 'settle']);

it('rejects new affiliate awards without a host cash policy', function (): void {
    $affiliate = Affiliate::create([
        'code' => 'HOST-AWARD-'.Str::upper(Str::random(6)),
        'name' => 'Host Award Policy Test',
        'status' => Active::class,
        'commission_type' => 'percentage',
        'commission_rate' => 1000,
        'currency' => 'MYR',
    ]);

    $result = RecordAffiliateAward::run(
        $affiliate,
        'host:reward-award:test-1',
        500,
        'MYR',
    );

    expect($result->disposition)->toBe(AffiliateOutcomeDisposition::Rejected)
        ->and($result->conversion)->toBeNull()
        ->and($affiliate->canBeAttributed())->toBeTrue();

    expect(AffiliateConversion::query()->count())->toBe(0)
        ->and(AffiliateBalance::query()->count())->toBe(0)
        ->and(AffiliatePayout::query()->count())->toBe(0)
        ->and(RewardBudgetMovement::query()->count())->toBe(0)
        ->and(RewardPayoutRequest::query()->count())->toBe(0);
});
