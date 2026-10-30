<?php

declare(strict_types=1);

use AIArmada\Affiliates\Actions\Conversions\RecordAffiliateAward;
use AIArmada\Affiliates\Enums\AffiliateOutcomeDisposition;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateBalance;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\States\Active;
use AIArmada\Rewards\Actions\ApproveRewardPayoutAction;
use AIArmada\Rewards\Actions\ConfirmRewardPayoutAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\ReconcileRewardPayoutAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\RegisterRewardPayeeAction;
use AIArmada\Rewards\Actions\RequestRewardPayoutAction;
use AIArmada\Rewards\Actions\SubmitRewardPayoutAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Actions\VerifyRewardPayeeAction;
use AIArmada\Rewards\Contracts\RewardFinancialReadinessChecker;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardBudgetMovementKind;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardPayoutRequestStatus;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardAwardEntry;
use AIArmada\Rewards\Models\RewardBudgetMovement;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use AIArmada\Rewards\Support\RewardBudgetLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

it('runs the manual bank payout from funding to confirmed fulfillment', function (): void {
    Http::preventStrayRequests();

    $staff = RewardLedgerFixtures::staffId();
    $requester = (string) Str::uuid();
    $approver = (string) Str::uuid();
    $confirmer = (string) Str::uuid();
    $memberId = (string) Str::uuid();

    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('payout-live', RewardProgramMode::Cash);

    RecordRewardFundingSourceAction::run('payout-live-source', 'MYR', 10000, 'user', $staff, 'staff');
    RecordRewardFundingAction::run($program, 'MYR', 10000, 'payout-live-source', 'user', $staff, 'staff');

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    EnrollRewardMemberAction::run(
        $program,
        'user',
        $memberId,
        $version,
        'member-portal',
        CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('payout-live-attendance', $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program,
        RewardComponent::Cash,
        'user',
        $memberId,
        RewardBeneficiaryRole::Recipient,
        'payout-live-attendance',
        CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->award->status)->toBe(RewardAwardStatus::Held)
        ->and($result->award->source_business_key)->toBe('payout-live-attendance')
        ->and($reader->read('payout-live-attendance'))->not->toBeNull();

    RegisterRewardPayeeAction::run(
        'user',
        $memberId,
        'bank_transfer',
        'payout-live-account',
        'Bank ••1234',
        'user',
        $memberId,
        'member',
    );

    $payee = VerifyRewardPayeeAction::run(
        'user',
        $memberId,
        'bank_transfer',
        'payout-live-account',
        'Bank ••1234',
        'user',
        $staff,
        'staff',
    );

    $transition = new TransitionRewardAwardAction(
        app(RewardFinancialReadinessChecker::class),
        $reader,
    );

    $award = $transition->handle(
        $result->award,
        RewardAwardStatus::Approved,
        'user',
        $staff,
        'staff',
        'source-and-funding-verified',
    );

    $request = RequestRewardPayoutAction::run(
        $award,
        $payee,
        'bank_transfer',
        'program',
        'user',
        $requester,
        'staff',
    );

    $request = ApproveRewardPayoutAction::run(
        $request,
        'user',
        $approver,
        'staff',
        'independent-finance-approval',
    );

    $request = SubmitRewardPayoutAction::run($request, 'user', $staff, 'staff');

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted)
        ->and($request->external_reference_id)->toBeNull()
        ->and($request->external_payout_id)->toBe('manual:reward-payout:'.$award->getKey());

    $request = ReconcileRewardPayoutAction::run($request, 'user', $staff, 'staff');

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted)
        ->and($request->confirmed_at)->toBeNull();

    $request = ConfirmRewardPayoutAction::run(
        $request,
        [
            'bank_reference' => 'PAYOUT-LIVE-BANK-1',
            'transferred_at' => '2026-06-17T00:00:00Z',
        ],
        'user',
        $confirmer,
        'staff',
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and($request->confirmed_at)->not->toBeNull()
        ->and($request->provider_reference)->toBe('PAYOUT-LIVE-BANK-1')
        ->and($request->external_reference_id)->toBeNull()
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))->toBe([
            'funded' => 10000,
            'reserved' => 0,
            'net_spent' => 500,
            'available' => 9500,
        ]);

    $fulfilled = $transition->handle(
        $award,
        RewardAwardStatus::Fulfilled,
        'user',
        $staff,
        'staff',
        'manual-transfer-confirmed',
        ['payout_request_id' => (string) $request->getKey()],
        'payout-live-fulfill',
    );

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled)
        ->and(RewardBudgetMovement::query()
            ->where('award_id', $award->getKey())
            ->where('kind', RewardBudgetMovementKind::Spend->value)
            ->count())->toBe(1)
        ->and(RewardAwardEntry::query()
            ->where('award_id', $award->getKey())
            ->where('to_status', RewardAwardStatus::Fulfilled->value)
            ->count())->toBe(1)
        ->and(AffiliateConversion::query()->count())->toBe(0)
        ->and(AffiliatePayout::query()->count())->toBe(0)
        ->and(AffiliateBalance::query()->count())->toBe(0);

    Http::assertNothingSent();
});

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
