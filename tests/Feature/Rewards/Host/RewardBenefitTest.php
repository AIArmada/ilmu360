<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\ApproveRewardPayoutAction;
use AIArmada\Rewards\Actions\CancelRewardBenefitGrantAction;
use AIArmada\Rewards\Actions\ConfirmRewardPayoutAction;
use AIArmada\Rewards\Actions\ConsumeRewardBenefitAction;
use AIArmada\Rewards\Actions\CreateRewardBenefitItemAction;
use AIArmada\Rewards\Actions\CreditRewardPointsAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\FulfillRewardBenefitAction;
use AIArmada\Rewards\Actions\OpenRewardRecoveryAction;
use AIArmada\Rewards\Actions\RecordRewardBenefitRecallAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\RedeemRewardBenefitAction;
use AIArmada\Rewards\Actions\RequestRewardPayoutAction;
use AIArmada\Rewards\Actions\RetireRewardBenefitItemAction;
use AIArmada\Rewards\Actions\ReverseRewardFulfillmentAction;
use AIArmada\Rewards\Actions\RevokeRewardBenefitGrantAction;
use AIArmada\Rewards\Actions\SettleRewardRecoveryAction;
use AIArmada\Rewards\Actions\SubmitRewardPayoutAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Actions\VerifyRewardPayeeAction;
use AIArmada\Rewards\Actions\WriteOffRewardRecoveryAction;
use AIArmada\Rewards\Contracts\RewardFinancialReadinessChecker;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardBenefitGrantStatus;
use AIArmada\Rewards\Enums\RewardBenefitItemStatus;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardEvaluationOutcome;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Enums\RewardRecoveryStatus;
use AIArmada\Rewards\Models\RewardBenefitGrant;
use AIArmada\Rewards\Models\RewardRecovery;
use AIArmada\Rewards\Support\RewardBenefitStockLedger;
use AIArmada\Rewards\Support\RewardBudgetLedger;
use AIArmada\Rewards\Support\RewardPointLedger;
use AIArmada\Vouchers\Actions\RecordVoucherUsage;
use Akaunting\Money\Currency;
use Akaunting\Money\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

function benefitStaff(int $count = 4): array
{
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $ids[] = (string) Str::uuid();
    }

    return $ids;
}

function benefitPointsProgram(string $prefix): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, RewardProgramMode::Points);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    return [$program, $version];
}

function benefitFundedMember($program, $version, int $points): array
{
    $memberId = (string) Str::uuid();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    CreditRewardPointsAction::run(
        'user', $memberId, $program->family_key, 'pts', $points,
        CarbonImmutable::parse('2026-06-11T00:00:00Z'), null,
        null, (string) $program->getKey(), 'seed:benefit-credit-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );

    return [$memberId];
}

function benefitBalances(string $memberId, string $family): array
{
    $buckets = RewardPointLedger::balancesFor('user', $memberId, $family, 'pts', CarbonImmutable::parse('2026-07-01T00:00:00Z'));

    return ['available' => $buckets->available, 'reserved' => $buckets->reserved, 'spent' => $buckets->spent];
}

it('creates and retires catalog items with frozen terms', function () {
    [$program] = benefitPointsProgram('ben-cat');
    [$staff] = benefitStaff();

    $item = CreateRewardBenefitItemAction::run(
        $program, 'ebook-credit', 'E-book credit', ['Good standing'], 100, 'pts', false, 10,
        'user', $staff, 'staff',
    );

    expect($item->status)->toBe(RewardBenefitItemStatus::Active)
        ->and(RewardBenefitStockLedger::balances($item)['available'])->toBe(10);

    expect(fn () => CreateRewardBenefitItemAction::run(
        $program, 'bad-both', 'Bad', ['x'], 100, 'pts', true, 10, 'user', $staff, 'staff',
    ))->toThrow(InvalidArgumentException::class, 'either a point cost or direct entitlement');

    $retired = RetireRewardBenefitItemAction::run($item, 'user', $staff, 'staff', 'season-over');

    expect($retired->status)->toBe(RewardBenefitItemStatus::Retired)
        ->and($retired->retired_at)->not->toBeNull();
});

it('redeems, fulfills, and consumes a point-funded benefit exactly', function () {
    [$program] = benefitPointsProgram('ben-redeem');
    [$memberId] = benefitFundedMember($program, $program->currentVersion, 500);
    [$staff, $operator] = benefitStaff();

    $item = CreateRewardBenefitItemAction::run(
        $program, 'workshop-seat', 'Workshop seat', ['Good standing'], 200, 'pts', false, 5,
        'user', $staff, 'staff',
    );

    $grant = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 2, 'red-001', 'manual',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($grant->status)->toBe(RewardBenefitGrantStatus::Reserved)
        ->and(benefitBalances($memberId, $program->family_key))
        ->toBe(['available' => 100, 'reserved' => 400, 'spent' => 0])
        ->and(RewardBenefitStockLedger::balances($item->refresh()))
        ->toBe(['capacity' => 5, 'reserved' => 2, 'consumed' => 0, 'available' => 3]);

    // Replay returns the stored grant; over-redemption refuses atomically.
    $replay = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 2, 'red-001', 'manual',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect((string) $replay->getKey())->toBe((string) $grant->getKey());

    expect(fn () => RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 4, 'red-002', 'manual',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class);

    expect(benefitBalances($memberId, $program->family_key)['reserved'])->toBe(400);

    $issued = FulfillRewardBenefitAction::run(
        $grant, ['handoff_reference' => 'DESK-42'], 'user', $operator, 'staff',
        CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    expect($issued->status)->toBe(RewardBenefitGrantStatus::Issued)
        ->and(benefitBalances($memberId, $program->family_key))
        ->toBe(['available' => 100, 'reserved' => 0, 'spent' => 400])
        ->and(RewardBenefitStockLedger::balances($item->refresh())['consumed'])->toBe(2);

    $consumed = ConsumeRewardBenefitAction::run(
        $issued, ['use_reference' => 'ATTENDED'], 'user', $memberId, 'member',
        CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    expect($consumed->status)->toBe(RewardBenefitGrantStatus::Consumed);
});

it('cancels a pending redemption and restores exactly once', function () {
    [$program] = benefitPointsProgram('ben-cancel');
    [$memberId] = benefitFundedMember($program, $program->currentVersion, 300);
    [$staff] = benefitStaff();

    $item = CreateRewardBenefitItemAction::run(
        $program, 'merch-pack', 'Merch pack', ['Good standing'], 150, 'pts', false, 4,
        'user', $staff, 'staff',
    );

    $grant = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 1, 'red-cancel-1', 'manual',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $cancelled = CancelRewardBenefitGrantAction::run(
        $grant, 'user', $memberId, 'member', 'changed-mind', CarbonImmutable::parse('2026-06-15T01:00:00Z'),
    );

    expect($cancelled->status)->toBe(RewardBenefitGrantStatus::Revoked)
        ->and(benefitBalances($memberId, $program->family_key))
        ->toBe(['available' => 300, 'reserved' => 0, 'spent' => 0])
        ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(4);
});

it('revokes an unused issued grant and refuses revocation after use', function () {
    [$program] = benefitPointsProgram('ben-revoke');
    [$memberId] = benefitFundedMember($program, $program->currentVersion, 300);
    [$staff, $operator] = benefitStaff();

    $item = CreateRewardBenefitItemAction::run(
        $program, 'course-seat', 'Course seat', ['Good standing'], 150, 'pts', false, 4,
        'user', $staff, 'staff',
    );

    $grant = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 1, 'red-revoke-1', 'manual',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $issued = FulfillRewardBenefitAction::run(
        $grant, ['handoff_reference' => 'DESK-7'], 'user', $operator, 'staff',
        CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    // Manual revocation needs a recorded recall first.
    expect(fn () => RevokeRewardBenefitGrantAction::run(
        $issued, 'user', $operator, 'staff', 'recall', CarbonImmutable::parse('2026-06-16T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'recall_reference');

    RecordRewardBenefitRecallAction::run(
        $issued, 'RECALL-1', 'user', $operator, 'staff', CarbonImmutable::parse('2026-06-16T01:30:00Z'),
    );

    $revoked = RevokeRewardBenefitGrantAction::run(
        $issued->refresh(), 'user', $operator, 'staff', 'seat-recalled', CarbonImmutable::parse('2026-06-16T02:00:00Z'),
    );

    expect($revoked->status)->toBe(RewardBenefitGrantStatus::Revoked)
        ->and(benefitBalances($memberId, $program->family_key)['available'])->toBe(300)
        ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(4);

    $grant2 = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 1, 'red-revoke-2', 'manual',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );
    $issued2 = FulfillRewardBenefitAction::run(
        $grant2, ['handoff_reference' => 'DESK-8'], 'user', $operator, 'staff',
        CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );
    ConsumeRewardBenefitAction::run(
        $issued2, ['use_reference' => 'USED'], 'user', $memberId, 'member',
        CarbonImmutable::parse('2026-06-17T02:00:00Z'),
    );

    expect(fn () => RevokeRewardBenefitGrantAction::run(
        $issued2->refresh(), 'user', $operator, 'staff', 'too-late', CarbonImmutable::parse('2026-06-17T03:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Only issued grants can be revoked');
});

it('issues coupons on fulfillment and consumes only on canonical usage', function () {
    [$program] = benefitPointsProgram('ben-coupon');
    $member = User::factory()->create();
    $memberId = (string) $member->getKey();
    [$staff, $operator] = benefitStaff();

    $version = $program->currentVersion()->firstOrFail();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    CreditRewardPointsAction::run(
        'user', $memberId, $program->family_key, 'pts', 400,
        CarbonImmutable::parse('2026-06-11T00:00:00Z'), null,
        null, (string) $program->getKey(), 'seed:coupon-credit-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );

    $item = CreateRewardBenefitItemAction::run(
        $program, 'store-credit', 'Store credit', ['Good standing'], 400, 'pts', false, 3,
        'user', $staff, 'staff', null, ['voucher' => ['type' => 'fixed', 'value' => 2000, 'currency' => 'MYR']],
    );

    $grant = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 1, 'red-coupon-1', 'coupon',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $issued = FulfillRewardBenefitAction::run(
        $grant, [], 'user', $operator, 'staff', CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    expect($issued->status)->toBe(RewardBenefitGrantStatus::Issued)
        ->and($issued->external_ref)->toStartWith('RB-');

    // Issuance is not use: consumption refuses without canonical usage.
    expect(fn () => ConsumeRewardBenefitAction::run(
        $issued, [], 'user', $memberId, 'member', CarbonImmutable::parse('2026-06-16T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'No canonical usage exists');

    $usage = RecordVoucherUsage::run(
        (string) $issued->external_ref,
        new Money(2000, new Currency('MYR')),
        'reward-benefit',
        ['idempotency_key' => 'coupon-use-1', 'grant_key' => (string) $issued->grant_key],
        $member,
        'benefit redemption use',
    );

    $consumed = ConsumeRewardBenefitAction::run(
        $issued->refresh(), ['voucher_usage_id' => (string) $usage->getKey()],
        'user', $memberId, 'member', CarbonImmutable::parse('2026-06-16T02:00:00Z'),
    );

    expect($consumed->status)->toBe(RewardBenefitGrantStatus::Consumed);
});

it('fulfills a direct-entitlement benefit award through the catalog', function () {
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('ben-direct', RewardProgramMode::Benefit);
    [$staff] = benefitStaff();

    // Benefit programs activate only with catalog coverage.
    expect(fn () => TransitionRewardProgramStatusAction::run(
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready),
        RewardProgramStatus::Active,
    ))->toThrow(InvalidArgumentException::class, 'benefit-catalog-unavailable');

    CreateRewardBenefitItemAction::run(
        $program, 'ebook-credit', 'E-book credit', ['Member account in good standing', 'One redemption per award'],
        null, null, true, 5, 'user', $staff, 'staff',
    );

    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $memberId = (string) Str::uuid();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-ben-direct-1', $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Benefit, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-ben-direct-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Held);

    $transition = new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader);

    $approved = $transition->handle(
        $result->award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'policy-ok',
        null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    $fulfilled = $transition->handle(
        $approved, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'grant-issue',
        null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);

    $grant = RewardBenefitGrant::query()->where('award_id', $fulfilled->getKey())->firstOrFail();

    expect($grant->status)->toBe(RewardBenefitGrantStatus::Reserved)
        ->and($grant->reservation_key)->toBeNull();

    $issued = FulfillRewardBenefitAction::run(
        $grant, ['handoff_reference' => 'LIBRARY-1'], 'user', $staff, 'staff',
        CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($issued->status)->toBe(RewardBenefitGrantStatus::Issued);
});

it('reverses a confirmed cash payout through recovery and settles the budget', function () {

    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('ben-cashrec', RewardProgramMode::Cash);
    [$funder, $staff, $verifier, $requester] = benefitStaff();
    $approver = (string) Str::uuid();
    $confirmer = (string) Str::uuid();

    RecordRewardFundingSourceAction::run(
        'TREASURY-ben-cashrec', 'MYR', 10000, 'user', $funder, 'staff',
        null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    RecordRewardFundingAction::run(
        $program, 'MYR', 10000, 'TREASURY-ben-cashrec', 'user', $funder, 'staff',
        null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $memberId = (string) Str::uuid();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-ben-cashrec-1', $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Cash, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-ben-cashrec-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-R', 'Bank ••00R',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $transition = new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader);

    $approved = $transition->handle(
        $result->award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'policy-ok',
        null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    $request = RequestRewardPayoutAction::run(
        $approved, $payee, 'bank_transfer', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );
    $request = ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    // Submitted-but-unpaid is never presented as received: fulfillment
    // refuses until the manual leg confirms, then stamps paid-only.
    expect(fn () => $transition->handle(
        $approved, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'paid-pending-confirm',
        null, null, CarbonImmutable::parse('2026-06-18T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'payout-not-confirmed:submitted');

    $request = ConfirmRewardPayoutAction::run(
        $request, ['bank_reference' => 'BANK-R'], 'user', $confirmer, 'staff',
        CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    $fulfilled = $transition->handle(
        $approved, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'paid',
        null, null, CarbonImmutable::parse('2026-06-19T01:00:00Z'),
    );

    $outcome = ReverseRewardFulfillmentAction::run(
        $fulfilled, 'user', $staff, 'staff', 'source-invalid', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'),
    );

    expect($outcome['award']->status)->toBe(RewardAwardStatus::Reversed)
        ->and($outcome['recovery_ids'])->toHaveCount(1);

    $recovery = RewardRecovery::query()->whereKey($outcome['recovery_ids'][0])->firstOrFail();

    expect($recovery->status)->toBe(RewardRecoveryStatus::Open)
        ->and($recovery->amount_minor)->toBe(500);

    // Settlement without proof refuses; confirmed recovery replenishes.
    expect(fn () => SettleRewardRecoveryAction::run(
        $recovery, [], 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-21T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'recovery_proof');

    $settled = SettleRewardRecoveryAction::run(
        $recovery, ['recovery_proof' => 'BANK-RETURN-1'], 'user', $staff, 'staff',
        CarbonImmutable::parse('2026-06-21T00:00:00Z'),
    );

    expect($settled->status)->toBe(RewardRecoveryStatus::Settled)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000]);

    $written = WriteOffRewardRecoveryAction::run(
        OpenRewardRecoveryAction::run(
            $fulfilled->refresh(), 'dispute', null, null, null, null, 50, 'pts',
            'user', $staff, 'staff', 'small-loss', null, CarbonImmutable::parse('2026-06-22T00:00:00Z'),
        ),
        'user', $staff, 'staff', 'unrecoverable', CarbonImmutable::parse('2026-06-23T00:00:00Z'),
    );

    expect($written->status)->toBe(RewardRecoveryStatus::WrittenOff);
});
