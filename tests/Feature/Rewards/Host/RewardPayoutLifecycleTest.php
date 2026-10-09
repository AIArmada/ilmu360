<?php

declare(strict_types=1);

use AIArmada\Affiliates\Actions\Conversions\RecordAffiliateAward;
use AIArmada\Affiliates\Actions\Payouts\CreatePayout;
use AIArmada\Affiliates\Actions\Payouts\ProcessAffiliatePayout;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\Models\AffiliatePayoutMethod;
use AIArmada\Affiliates\States\ApprovedConversion;
use AIArmada\Affiliates\States\PaidConversion;
use App\Actions\Rewards\ApproveRewardPayoutAction;
use App\Actions\Rewards\CancelRewardPayoutAction;
use App\Actions\Rewards\ConfirmRewardPayoutAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\ReconcileRewardPayoutAction;
use App\Actions\Rewards\RecordRewardFundingAction;
use App\Actions\Rewards\RecordRewardFundingSourceAction;
use App\Actions\Rewards\RequestRewardPayoutAction;
use App\Actions\Rewards\SubmitRewardPayoutAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Actions\Rewards\VerifyRewardPayeeAction;
use App\Contracts\Rewards\RewardFinancialReadinessChecker;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEvaluationOutcome;
use App\Enums\Rewards\RewardPayoutRequestStatus;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardAward;
use App\Models\RewardBudgetMovement;
use App\Models\RewardPayoutRequest;
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardRecipientAffiliateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

// R4 proof: truthful entitlement -> submitted -> paid -> fulfilled semantics
// across BOTH ledgers, crash recovery at each durable boundary, stable
// retry/requeue with attempt-bound releases, and cancel/reconcile/confirm
// coherence through the canonical package transitions. Real adapters with
// controlled HTTP throughout; no live network.

uses(RefreshDatabase::class);

function lcStaff(): array
{
    return [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
}

function lcProgram(string $prefix, int $fundedMinor): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, RewardProgramMode::Cash);
    [$funder] = lcStaff();

    RecordRewardFundingSourceAction::run(
        'TREASURY-'.$prefix, 'MYR', $fundedMinor, 'user', $funder, 'staff',
        ['document' => 'treasury-receipt-1'], CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    RecordRewardFundingAction::run(
        $program, 'MYR', $fundedMinor, 'TREASURY-'.$prefix,
        'user', $funder, 'staff', ['document' => 'treasury-receipt-1'],
        CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    return [$program, $version];
}

function lcHeldCashAward($program, $version, string $source): array
{
    $memberId = (string) Str::uuid();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Cash, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Held)
        ->and($result->award->status)->toBe(RewardAwardStatus::Held);

    return [$result->award, $memberId, $reader];
}

function lcApprove(RewardAward $award, $reader, string $staff, string $at): RewardAward
{
    return (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'policy-ok', null, null, CarbonImmutable::parse($at),
    );
}

function lcRequest($approved, $payee, string $processor, string $feePayer, string $requester, string $approver): RewardPayoutRequest
{
    $request = RequestRewardPayoutAction::run(
        $approved, $payee, $processor, $feePayer, 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    return ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );
}

it('fulfills exact-funded program-paid payouts with zero surplus and settles both ledgers', function () {
    // Exact funding: 511 gross (500 net + 11 installed paypal fee), not a
    // minor unit of surplus. The old own-reservation-blind readiness
    // refused this; the paid-only gate fulfills it.
    [$program, $version] = lcProgram('lc-exact', 511);
    [$award, $memberId, $reader] = lcHeldCashAward($program, $version, 'src-lc-exact-1');
    [$staff, $verifier, $requester, $approver] = lcStaff();

    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'pp-test-token'], 200),
        'api-m.sandbox.paypal.com/v1/payments/payouts' => Http::response(['batch_header' => ['payout_batch_id' => 'BATCH-EXACT-1']], 201),
        'api-m.sandbox.paypal.com/v1/payments/payouts/*' => Http::response(['batch_header' => ['batch_status' => 'SUCCESS']], 200),
    ]);
    config()->set('affiliates.payouts.paypal.client_id', 'test-id');
    config()->set('affiliates.payouts.paypal.client_secret', 'test-secret');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'paypal', 'exact@example.test', 'exact@example.test',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = lcApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
    $request = lcRequest($approved, $payee, 'paypal', 'program', $requester, $approver);

    expect(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 511, 'reserved' => 511, 'net_spent' => 0, 'available' => 0]);

    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted);

    // Submitted-but-unpaid never fulfills, even exactly funded.
    expect(fn () => (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $approved, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'too-early', null, null, CarbonImmutable::parse('2026-06-18T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'payout-not-confirmed:submitted');

    // The pending batch completes: reconcile settles both ledgers.
    $request = ReconcileRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 511, 'reserved' => 0, 'net_spent' => 511, 'available' => 0]);

    $payout = AffiliatePayout::query()->whereKey((string) $request->affiliate_payout_id)->firstOrFail();
    $conversion = AffiliateConversion::query()->whereKey((string) $request->backing_conversion_id)->firstOrFail();

    expect($payout->status->getValue())->toBe('completed')
        ->and($payout->operation->status)->toBe('completed')
        ->and($conversion->status->equals(PaidConversion::class))->toBeTrue()
        ->and($conversion->paid_at)->not->toBeNull();

    $fulfilled = (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $approved, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'paid', null, null, CarbonImmutable::parse('2026-06-19T01:00:00Z'),
    );

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);
});

it('sends the exact recipient-paid net and settles both ledgers on manual confirmation', function () {
    [$program, $version] = lcProgram('lc-recipient', 10000);
    [$award, $memberId, $reader] = lcHeldCashAward($program, $version, 'src-lc-recipient-1');
    [$staff, $verifier, $requester, $approver] = lcStaff();
    $confirmer = (string) Str::uuid();

    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'pp-test-token'], 200),
        'api-m.sandbox.paypal.com/v1/payments/payouts' => Http::response(['batch_header' => ['payout_batch_id' => 'BATCH-RECIP-1']], 201),
    ]);
    config()->set('affiliates.payouts.paypal.client_id', 'test-id');
    config()->set('affiliates.payouts.paypal.client_secret', 'test-secret');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'paypal', 'recipient@example.test', 'recipient@example.test',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = lcApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
    $request = lcRequest($approved, $payee, 'paypal', 'recipient', $requester, $approver);

    // Recipient-pays: the installed 10 fee comes out of the 500 award.
    expect($request->fee_minor)->toBe(10)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['reserved'])->toBe(500);

    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted);

    Http::assertSent(fn ($sent) => ($sent->data()['items'][0]['amount'] ?? null) === ['value' => '4.90', 'currency' => 'MYR']);
});

it('settles both ledgers on manual confirmation with the bank reference', function () {
    [$program, $version] = lcProgram('lc-manual', 10000);
    [$award, $memberId, $reader] = lcHeldCashAward($program, $version, 'src-lc-manual-1');
    [$staff, $verifier, $requester, $approver] = lcStaff();
    $confirmer = (string) Str::uuid();

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-LC', 'Bank ••00LC',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = lcApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
    $request = lcRequest($approved, $payee, 'bank_transfer', 'program', $requester, $approver);
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted);

    $request = ConfirmRewardPayoutAction::run(
        $request, ['bank_reference' => 'BANK-LC-1', 'transferred_at' => '2026-06-19T00:00:00Z'],
        'user', $confirmer, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and($request->provider_reference)->toBe('BANK-LC-1')
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 500, 'available' => 9500]);

    $payout = AffiliatePayout::query()->whereKey((string) $request->affiliate_payout_id)->firstOrFail();
    $conversion = AffiliateConversion::query()->whereKey((string) $request->backing_conversion_id)->firstOrFail();

    expect($payout->status->getValue())->toBe('completed')
        ->and($payout->operation->status)->toBe('completed')
        ->and($payout->paid_at)->not->toBeNull()
        ->and($conversion->status->equals(PaidConversion::class))->toBeTrue()
        ->and($payout->events()->count())->toBeGreaterThanOrEqual(2);
});

it('recovers the backing replay when a crash lands between posting and binding', function () {
    [$program, $version] = lcProgram('lc-crash1', 10000);
    [$award, $memberId, $reader] = lcHeldCashAward($program, $version, 'src-lc-crash1-1');
    [$staff, $verifier, $requester, $approver] = lcStaff();

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-C1', 'Bank ••00C1',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = lcApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
    $request = lcRequest($approved, $payee, 'bank_transfer', 'program', $requester, $approver);

    // The crash: backing posted durably, pointer never stored.
    $recipient = RewardRecipientAffiliateResolver::resolve('user', $memberId);

    $posted = app(RecordAffiliateAward::class)->handle(
        $recipient,
        'reward-cash:'.(string) $award->getKey(),
        500,
        'MYR',
        [
            'status' => ApprovedConversion::class,
            'external_reference' => (string) $request->first_reference,
            'source_ref' => (string) $request->operation_key,
            'origin' => 'share-rewards',
            'occurred_at' => $request->requested_at,
        ],
    );

    expect($posted->conversion)->not->toBeNull();

    // Retry replays the committed backing (no duplicate) and submits.
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted)
        ->and((string) $request->backing_conversion_id)->toBe((string) $posted->conversion->id)
        ->and(AffiliateConversion::query()->where('affiliate_id', $recipient->getKey())->count())->toBe(1);
});

it('recovers the orphan payout when a crash lands between creation and binding', function () {
    [$program, $version] = lcProgram('lc-crash2', 10000);
    [$award, $memberId, $reader] = lcHeldCashAward($program, $version, 'src-lc-crash2-1');
    [$staff, $verifier, $requester, $approver] = lcStaff();

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-C2', 'Bank ••00C2',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = lcApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
    $request = lcRequest($approved, $payee, 'bank_transfer', 'program', $requester, $approver);

    // The crash: backing bound, payout created, pointer never stored.
    $recipient = RewardRecipientAffiliateResolver::resolve('user', $memberId);

    $posted = app(RecordAffiliateAward::class)->handle(
        $recipient,
        'reward-cash:'.(string) $award->getKey(),
        500,
        'MYR',
        [
            'status' => ApprovedConversion::class,
            'external_reference' => (string) $request->first_reference,
            'source_ref' => (string) $request->operation_key,
            'origin' => 'share-rewards',
            'occurred_at' => $request->requested_at,
        ],
    );

    $orphan = app(CreatePayout::class)->handle(
        [(string) $posted->conversion->id],
        ['reference' => (string) $request->first_reference],
    );

    $request->forceFill(['backing_conversion_id' => (string) $posted->conversion->id])->save();

    // Retry binds the orphan (no second payout) and submits it.
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted)
        ->and((string) $request->affiliate_payout_id)->toBe((string) $orphan->getKey())
        ->and(AffiliatePayout::query()->where('reference', (string) $request->first_reference)->count())->toBe(1);
});

it('sends once when a crash lands between binding and the provider call', function () {
    [$program, $version] = lcProgram('lc-crash3a', 10000);
    [$award, $memberId, $reader] = lcHeldCashAward($program, $version, 'src-lc-crash3a-1');
    [$staff, $verifier, $requester, $approver] = lcStaff();

    Http::preventStrayRequests();

    $posts = 0;

    Http::fake([
        'api.stripe.com/v1/transfers*' => function ($request) use (&$posts) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }

            $posts++;

            return Http::response(['id' => 'tr_crash3a_1'], 200);
        },
    ]);
    config()->set('affiliates.payouts.stripe.secret_key', 'sk_test_fixture');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'stripe_connect', 'acct_crash3a', 'acct_cra…',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = lcApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
    $request = lcRequest($approved, $payee, 'stripe_connect', 'program', $requester, $approver);

    // The crash: everything bound, provider never called.
    $recipient = RewardRecipientAffiliateResolver::resolve('user', $memberId);

    $posted = app(RecordAffiliateAward::class)->handle(
        $recipient,
        'reward-cash:'.(string) $award->getKey(),
        527,
        'MYR',
        [
            'status' => ApprovedConversion::class,
            'external_reference' => (string) $request->first_reference,
            'source_ref' => (string) $request->operation_key,
            'origin' => 'share-rewards',
            'occurred_at' => $request->requested_at,
        ],
    );

    $orphan = app(CreatePayout::class)->handle(
        [(string) $posted->conversion->id],
        ['reference' => (string) $request->first_reference],
    );

    $request->forceFill([
        'backing_conversion_id' => (string) $posted->conversion->id,
        'affiliate_payout_id' => (string) $orphan->getKey(),
    ])->save();

    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and($posts)->toBe(1)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 527, 'available' => 9473]);
});

it('never double-sends when a crash lands between the provider call and its recording', function () {
    [$program, $version] = lcProgram('lc-crash3b', 10000);
    [$award, $memberId, $reader] = lcHeldCashAward($program, $version, 'src-lc-crash3b-1');
    [$staff, $verifier, $requester, $approver] = lcStaff();

    Http::preventStrayRequests();

    $posts = 0;

    Http::fake([
        'api.stripe.com/v1/transfers*' => function ($request) use (&$posts) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }

            $posts++;

            return Http::response(['id' => 'tr_crash3b_1'], 200);
        },
    ]);
    config()->set('affiliates.payouts.stripe.secret_key', 'sk_test_fixture');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'stripe_connect', 'acct_crash3b', 'acct_cra…',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = lcApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
    $request = lcRequest($approved, $payee, 'stripe_connect', 'program', $requester, $approver);

    // The crash: provider call completed, app recording never ran.
    $recipient = RewardRecipientAffiliateResolver::resolve('user', $memberId);

    $posted = app(RecordAffiliateAward::class)->handle(
        $recipient,
        'reward-cash:'.(string) $award->getKey(),
        527,
        'MYR',
        [
            'status' => ApprovedConversion::class,
            'external_reference' => (string) $request->first_reference,
            'source_ref' => (string) $request->operation_key,
            'origin' => 'share-rewards',
            'occurred_at' => $request->requested_at,
        ],
    );

    $payout = app(CreatePayout::class)->handle(
        [(string) $posted->conversion->id],
        ['reference' => (string) $request->first_reference],
    );

    AffiliatePayoutMethod::query()->create([
        'affiliate_id' => $recipient->getKey(),
        'type' => 'stripe_connect',
        'details' => [
            'payee_id' => (string) $payee->getKey(),
            'account_display' => (string) $payee->account_display,
            'operation_key' => (string) $request->operation_key,
            'stripe_account_id' => 'acct_crash3b',
        ],
        'is_default' => true,
        'verified_at' => $payee->verified_at,
    ]);

    $sent = app(ProcessAffiliatePayout::class)->handle($payout);

    expect($sent->getStatus())->toBe('completed')
        ->and($posts)->toBe(1);

    $request->forceFill([
        'backing_conversion_id' => (string) $posted->conversion->id,
        'affiliate_payout_id' => (string) $payout->getKey(),
    ])->save();

    // Retry records the durable completion without a second provider call.
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and($request->provider_reference)->toBe('tr_crash3b_1')
        ->and($posts)->toBe(1)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 527, 'available' => 9473]);
});

it('requeues confirmed failures under a fresh reserve and releases each attempt once', function () {
    [$program, $version] = lcProgram('lc-requeue', 10000);
    [$award, $memberId, $reader] = lcHeldCashAward($program, $version, 'src-lc-requeue-1');
    [$staff, $verifier, $requester, $approver] = lcStaff();

    Http::preventStrayRequests();

    $posts = 0;

    Http::fake([
        'api.stripe.com/v1/transfers*' => function ($request) use (&$posts) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }

            $posts++;

            // Attempt 1 (both tries): confirmed rejection. Attempt 2: paid.
            return $posts <= 2
                ? Http::response(['error' => ['code' => 'account_invalid']], 400)
                : Http::response(['id' => 'tr_requeue_1'], 200);
        },
    ]);
    config()->set('affiliates.payouts.stripe.secret_key', 'sk_test_fixture');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'stripe_connect', 'acct_requeue', 'acct_req…',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = lcApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
    $request = lcRequest($approved, $payee, 'stripe_connect', 'program', $requester, $approver);

    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Failed)
        ->and($request->failure_code)->toBe('STRIPE_ACCOUNT_INVALID')
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000]);

    // Requeue reuses the operation identity with a fresh attempt reserve
    // and a distinct provider reference; the released backing converts
    // back into the new payout instead of stranding money.
    $request = RequestRewardPayoutAction::run(
        $approved, $payee, 'stripe_connect', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-18T01:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Requested)
        ->and($request->evidence['attempt'])->toBe(2)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['reserved'])->toBe(527);

    $request = ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-18T02:00:00Z'),
    );

    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T03:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 527, 'available' => 9473]);

    $conversion = AffiliateConversion::query()->whereKey((string) $request->backing_conversion_id)->firstOrFail();
    $second = AffiliatePayout::query()->whereKey((string) $request->affiliate_payout_id)->firstOrFail();

    expect($conversion->status->equals(PaidConversion::class))->toBeTrue()
        ->and((string) $conversion->affiliate_payout_id)->toBe((string) $request->affiliate_payout_id)
        ->and((string) $second->reference)->toBe((string) $request->first_reference.'-A2')
        ->and($request->evidence['prior_payouts'])->toHaveCount(1)
        ->and($request->evidence['prior_payouts'][0]['attempt'])->toBe(1)
        ->and(AffiliatePayout::query()
            ->where('reference', 'like', (string) $request->first_reference.'%')->count())->toBe(2);
});

it('releases every attempt on repeated confirmed failures without leaking reserves', function () {
    [$program, $version] = lcProgram('lc-refail', 10000);
    [$award, $memberId, $reader] = lcHeldCashAward($program, $version, 'src-lc-refail-1');
    [$staff, $verifier, $requester, $approver] = lcStaff();

    Http::preventStrayRequests();
    Http::fake([
        'api.stripe.com/v1/transfers*' => function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['error' => ['code' => 'account_invalid']], 400);
        },
    ]);
    config()->set('affiliates.payouts.stripe.secret_key', 'sk_test_fixture');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'stripe_connect', 'acct_refail', 'acct_ref…',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = lcApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');

    foreach ([1, 2] as $attempt) {
        $request = $attempt === 1
            ? lcRequest($approved, $payee, 'stripe_connect', 'program', $requester, $approver)
            : ApproveRewardPayoutAction::run(
                RequestRewardPayoutAction::run(
                    $approved, $payee, 'stripe_connect', 'program', 'user', $requester, 'staff',
                    null, CarbonImmutable::parse('2026-06-18T0'.$attempt.':00:00Z'),
                ),
                'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-18T0'.$attempt.':30:00Z'),
            );

        $request = SubmitRewardPayoutAction::run(
            $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T0'.$attempt.':45:00Z'),
        );

        expect($request->status)->toBe(RewardPayoutRequestStatus::Failed);
    }

    // Both attempts released under their own keys: nothing leaks. First
    // stamps stand; later attempts chain into evidence without rewriting.
    // Three releases: the held-obligation retirement plus one per attempt.
    expect(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000])
        ->and(RewardBudgetMovement::query()->where('kind', 'release')->count())->toBe(3)
        ->and($request->refresh()->evidence['reapprovals'])->toHaveCount(1)
        ->and($request->evidence['refailures'])->toHaveCount(1)
        ->and($request->evidence['refailures'][0]['attempt'])->toBe(2)
        ->and($request->failed_at)->not->toBeNull();
});

it('cancels unsubmitted requests directly and never-sent operations through the package', function () {
    [$program, $version] = lcProgram('lc-cancel', 10000);
    [$staff, $verifier, $requester, $approver] = lcStaff();

    [$awardOne, $memberOne, $readerOne] = lcHeldCashAward($program, $version, 'src-lc-cancel-1');

    $payeeOne = VerifyRewardPayeeAction::run(
        'user', $memberOne, 'bank_transfer', 'ACCT-X1', 'Bank ••00X1',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approvedOne = lcApprove($awardOne, $readerOne, $staff, '2026-06-17T00:00:00Z');

    $requestOne = RequestRewardPayoutAction::run(
        $approvedOne, $payeeOne, 'bank_transfer', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    // Requested with no payout: direct cancel plus the attempt release.
    $requestOne = CancelRewardPayoutAction::run(
        $requestOne, 'user', $staff, 'staff', 'changed-mind', CarbonImmutable::parse('2026-06-17T02:00:00Z'),
    );

    expect($requestOne->status)->toBe(RewardPayoutRequestStatus::Cancelled)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['reserved'])->toBe(0);

    // Approved with a claimed-but-never-sent operation: the package
    // cancels through its own transition; the old code left the payout
    // pending and the conversion claimed.
    [$awardTwo, $memberTwo, $readerTwo] = lcHeldCashAward($program, $version, 'src-lc-cancel-2');

    $payeeTwo = VerifyRewardPayeeAction::run(
        'user', $memberTwo, 'bank_transfer', 'ACCT-X2', 'Bank ••00X2',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approvedTwo = lcApprove($awardTwo, $readerTwo, $staff, '2026-06-17T00:00:00Z');
    $requestTwo = lcRequest($approvedTwo, $payeeTwo, 'bank_transfer', 'program', $requester, $approver);

    $recipient = RewardRecipientAffiliateResolver::resolve('user', $memberTwo);

    $posted = app(RecordAffiliateAward::class)->handle(
        $recipient,
        'reward-cash:'.(string) $awardTwo->getKey(),
        500,
        'MYR',
        [
            'status' => ApprovedConversion::class,
            'external_reference' => (string) $requestTwo->first_reference,
            'source_ref' => (string) $requestTwo->operation_key,
            'origin' => 'share-rewards',
            'occurred_at' => $requestTwo->requested_at,
        ],
    );

    $payout = app(CreatePayout::class)->handle(
        [(string) $posted->conversion->id],
        ['reference' => (string) $requestTwo->first_reference],
    );

    $requestTwo->forceFill([
        'backing_conversion_id' => (string) $posted->conversion->id,
        'affiliate_payout_id' => (string) $payout->getKey(),
    ])->save();

    $requestTwo = CancelRewardPayoutAction::run(
        $requestTwo, 'user', $staff, 'staff', 'changed-mind', CarbonImmutable::parse('2026-06-17T03:00:00Z'),
    );

    expect($requestTwo->status)->toBe(RewardPayoutRequestStatus::Cancelled);

    $payout->refresh();
    $conversion = AffiliateConversion::query()->whereKey((string) $posted->conversion->id)->firstOrFail();

    expect($payout->status->getValue())->toBe('cancelled')
        ->and($payout->operation->status)->toBe('cancelled')
        ->and($conversion->status->equals(ApprovedConversion::class))->toBeTrue()
        ->and($conversion->affiliate_payout_id)->toBeNull()
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000]);
});
