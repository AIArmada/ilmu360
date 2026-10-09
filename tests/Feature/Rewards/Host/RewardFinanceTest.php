<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\AffiliatePayout;
use AIArmada\Affiliates\Models\AffiliatePayoutMethod;
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
use App\Support\Rewards\RewardBudgetLedger;
use App\Support\Rewards\RewardPayoutProcessorCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

function financeStaff(): array
{
    return [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
}

function financeProgram(string $prefix, int $fundedMinor = 10000): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, RewardProgramMode::Cash);
    [$funder] = financeStaff();

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

function financeHeldCashAward($program, $version, string $source): array
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

function financeApprove(RewardAward $award, $reader, string $staff, string $at): RewardAward
{
    return (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'policy-ok', null, null, CarbonImmutable::parse($at),
    );
}

it('refuses cash activation until confirmed funding exists, then activates', function () {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('fin-gate', RewardProgramMode::Cash);

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);

    expect(fn () => TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active))
        ->toThrow(InvalidArgumentException::class, 'no-confirmed-funding');

    expect(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 0, 'reserved' => 0, 'net_spent' => 0, 'available' => 0]);

    [$funder] = financeStaff();

    RecordRewardFundingSourceAction::run(
        'TREASURY-fin-gate', 'MYR', 10000, 'user', $funder, 'staff',
        ['document' => 'treasury-receipt-1'], CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    RecordRewardFundingAction::run(
        $program, 'MYR', 10000, 'TREASURY-fin-gate', 'user', $funder, 'staff',
        ['document' => 'treasury-receipt-1'], CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    $active = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    expect($active->status)->toBe(RewardProgramStatus::Active)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000]);
});

it('refuses cash approval without verified payee, then approves when funded and verified', function () {
    [$program, $version] = financeProgram('fin-approve');
    [$award, , $reader] = financeHeldCashAward($program, $version, 'src-fin-approve-1');
    [$staff] = financeStaff();

    expect(fn () => financeApprove($award, $reader, $staff, '2026-06-17T00:00:00Z'))
        ->toThrow(InvalidArgumentException::class, 'payee-unverified');

    [$verifier] = financeStaff();
    $memberId = (string) $award->recipient_id;

    expect(fn () => VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-1', 'Bank ••0001',
        'user', $memberId, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'self-verification');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-1', 'Bank ••0001',
        'user', $verifier, 'staff', ['evidence' => 'bank-statement-1'],
        CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = financeApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');

    expect($approved->status)->toBe(RewardAwardStatus::Approved)
        ->and($payee->verified_by)->toBe($verifier);
});

it('runs the manual bank pipeline from request to independent confirmation and fulfillment', function () {
    [$program, $version] = financeProgram('fin-bank');
    [$award, $memberId, $reader] = financeHeldCashAward($program, $version, 'src-fin-bank-1');
    [$staff, $verifier, $requester, $approver] = financeStaff();
    $confirmer = (string) Str::uuid();

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-9', 'Bank ••0009',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = financeApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');

    // Recipient-owned requests are prohibited.
    expect(fn () => RequestRewardPayoutAction::run(
        $approved, $payee, 'bank_transfer', 'program', 'user', $memberId, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Recipient-owned');

    $request = RequestRewardPayoutAction::run(
        $approved, $payee, 'bank_transfer', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Requested)
        ->and($request->operation_key)->toBe('reward-payout:'.(string) $award->getKey())
        ->and($request->first_reference)->toStartWith('RWP-')
        ->and($request->amount_minor)->toBe(500)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 500, 'net_spent' => 0, 'available' => 9500]);

    // Requester cannot approve their own request.
    expect(fn () => ApproveRewardPayoutAction::run(
        $request, 'user', $requester, 'staff', 'self-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'independent of the requester');

    $request = ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Approved);

    // Fulfillment refuses before submission; the award stays approved.
    expect(fn () => (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $approved, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'pay-now', null, null, CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'payout-not-confirmed:approved');

    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted)
        ->and($request->backing_conversion_id)->not->toBeNull()
        ->and($request->affiliate_payout_id)->not->toBeNull();

    // Requester cannot confirm their own request either.
    expect(fn () => ConfirmRewardPayoutAction::run(
        $request, ['bank_reference' => 'BANK-1'], 'user', $requester, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'independent of the requester');

    $request = ConfirmRewardPayoutAction::run(
        $request, ['bank_reference' => 'BANK-1', 'transferred_at' => '2026-06-19T00:00:00Z'],
        'user', $confirmer, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 500, 'available' => 9500]);

    $fulfilled = (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $approved, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'paid', null, null, CarbonImmutable::parse('2026-06-19T01:00:00Z'),
    );

    expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled)
        ->and($fulfilled->fulfilled_at)->not->toBeNull();
});

it('discovers real processors and refuses unsupported activation', function () {
    $catalog = app(RewardPayoutProcessorCatalog::class);
    $described = $catalog->describe();

    expect($described)->toHaveKey('bank_transfer')
        ->and($described['bank_transfer']['supported'])->toBeTrue()
        ->and($described)->toHaveKeys(['paypal', 'stripe_connect']);

    // Missing credentials report the INSTALLED keys the processors read.
    expect($catalog->isSupported('stripe_connect'))->toBeFalse()
        ->and($catalog->missingCredentials('stripe_connect'))->toBe(['affiliates.payouts.stripe.secret_key'])
        ->and($catalog->isSupported('paypal'))->toBeFalse()
        ->and($catalog->missingCredentials('paypal'))
        ->toBe(['affiliates.payouts.paypal.client_id', 'affiliates.payouts.paypal.client_secret']);

    // Ready transitions: setting the installed keys activates support.
    config()->set('affiliates.payouts.stripe.secret_key', 'sk_test_fixture');
    config()->set('affiliates.payouts.paypal.client_id', 'fixture-id');
    config()->set('affiliates.payouts.paypal.client_secret', 'fixture-secret');

    expect($catalog->isSupported('stripe_connect'))->toBeTrue()
        ->and($catalog->isSupported('paypal'))->toBeTrue();

    // Back to missing: support drops again, never from enum alone.
    config()->set('affiliates.payouts.stripe.secret_key', '');

    expect($catalog->isSupported('stripe_connect'))->toBeFalse();

    // Unknown credential contracts never fake support.
    expect($catalog->isSupported('wise'))->toBeFalse();
});

it('sends paypal payouts through the installed adapter with exact net and stable idempotency', function () {
    [$program, $version] = financeProgram('fin-paypal');
    [$staff, $verifier, $requester, $approver] = financeStaff();

    // Controlled HTTP against the REAL registered paypal processor: no
    // replacement provider class, no live network. The first operation
    // fails every attempt (unknown); the second succeeds (submitted).
    Http::preventStrayRequests();

    $posts = 0;

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'pp-test-token'], 200),
        'api-m.sandbox.paypal.com/v1/payments/payouts*' => function () use (&$posts) {
            $posts++;

            return $posts <= 2
                ? Http::response(['name' => 'SERVER_ERROR'], 500)
                : Http::response(['batch_header' => ['payout_batch_id' => 'BATCH-REAL-1']], 201);
        },
    ]);
    config()->set('affiliates.payouts.paypal.client_id', 'test-id');
    config()->set('affiliates.payouts.paypal.client_secret', 'test-secret');

    expect(app(RewardPayoutProcessorCatalog::class)->isSupported('paypal'))->toBeTrue();

    [$awardOne, $memberOne, $readerOne] = financeHeldCashAward($program, $version, 'src-fin-paypal-1');

    // A malformed destination refuses at registration via the installed
    // validation contract — never at send time.
    expect(fn () => VerifyRewardPayeeAction::run(
        'user', $memberOne, 'paypal', 'not-an-email', 'not-an-email',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'refused by [paypal]');

    $payeeOne = VerifyRewardPayeeAction::run(
        'user', $memberOne, 'paypal', 'member-one@example.test', 'member-one@example.test',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approvedOne = financeApprove($awardOne, $readerOne, $staff, '2026-06-17T00:00:00Z');

    $requestOne = RequestRewardPayoutAction::run(
        $approvedOne, $payeeOne, 'paypal', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    // Program-paid gross-up is exact under the installed schedule:
    // 511 gross - 11 fee = 500 promised net. The destination ref freezes.
    expect($requestOne->fee_minor)->toBe(11)
        ->and($requestOne->payee_snapshot['gross_minor'])->toBe(511)
        ->and($requestOne->payee_snapshot['account_ref'])->toBe('member-one@example.test')
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 511, 'net_spent' => 0, 'available' => 9489]);

    $requestOne = ApproveRewardPayoutAction::run(
        $requestOne, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );

    $requestOne = SubmitRewardPayoutAction::run(
        $requestOne, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    // Provider 500 on every attempt: unknown, no reference, reservation
    // kept, nothing spent. cancel=false (the installed paypal cancel) is
    // NOT confirmed failure.
    expect($requestOne->status)->toBe(RewardPayoutRequestStatus::Unknown)
        ->and($requestOne->provider_reference)->toBeNull()
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['reserved'])->toBe(511);

    expect(fn () => CancelRewardPayoutAction::run(
        $requestOne, 'user', $staff, 'staff', 'give-up', CarbonImmutable::parse('2026-06-18T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'did not confirm cancellation');

    expect($requestOne->refresh()->status)->toBe(RewardPayoutRequestStatus::Unknown);

    // Fulfillment refuses while the payout is unknown; award stays approved.
    expect(fn () => (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $readerOne))->handle(
        $approvedOne, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'pay-now', null, null, CarbonImmutable::parse('2026-06-18T02:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'payout-not-confirmed:unknown');

    // A paypal unknown carries no batch id, so installed reconciliation
    // cannot prove anything: the request honestly stays unknown with
    // its reservation and original operation intact.
    $requestOne = ReconcileRewardPayoutAction::run(
        $requestOne, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T03:00:00Z'),
    );

    expect($requestOne->status)->toBe(RewardPayoutRequestStatus::Unknown)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['reserved'])->toBe(511);

    // Second operation: success submits with the batch reference while
    // the first operation stays unknown and reserved.
    [$awardTwo, $memberTwo, $readerTwo] = financeHeldCashAward($program, $version, 'src-fin-paypal-2');

    $payeeTwo = VerifyRewardPayeeAction::run(
        'user', $memberTwo, 'paypal', 'member-two@example.test', 'member-two@example.test',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approvedTwo = financeApprove($awardTwo, $readerTwo, $staff, '2026-06-17T00:00:00Z');

    $requestTwo = RequestRewardPayoutAction::run(
        $approvedTwo, $payeeTwo, 'paypal', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );
    $requestTwo = ApproveRewardPayoutAction::run(
        $requestTwo, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );
    $requestTwo = SubmitRewardPayoutAction::run(
        $requestTwo, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T04:00:00Z'),
    );

    expect($requestTwo->status)->toBe(RewardPayoutRequestStatus::Submitted)
        ->and($requestTwo->provider_reference)->toBe('BATCH-REAL-1')
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 1022, 'net_spent' => 0, 'available' => 8978]);

    // Recipient payload, exact net, and per-operation idempotency: both
    // attempts of the failed operation carried the SAME batch id, and
    // the successful operation reached its own frozen recipient.
    $calls = Http::recorded(fn ($request) => str_ends_with($request->url(), '/v1/payments/payouts'))->values();

    expect($calls)->toHaveCount(3);

    $first = $calls[0][0]->data();
    $retry = $calls[1][0]->data();
    $second = $calls[2][0]->data();

    expect($first['sender_batch_header']['sender_batch_id'])
        ->toBe($retry['sender_batch_header']['sender_batch_id'])
        ->and($first['items'][0]['receiver'])->toBe('member-one@example.test')
        ->and($second['items'][0])->toBe([
            'recipient_type' => 'EMAIL',
            'amount' => ['value' => '5.00', 'currency' => 'MYR'],
            'sender_item_id' => $second['sender_batch_header']['sender_batch_id'],
            'receiver' => 'member-two@example.test',
        ])
        ->and($second['sender_batch_header']['sender_batch_id'])
        ->not->toBe($first['sender_batch_header']['sender_batch_id']);
});

it('resolves stripe unknown payouts by resend or late completion with exact accounting', function () {
    [$program, $version] = financeProgram('fin-stripe');
    [$staff, $verifier, $requester, $approver] = financeStaff();

    // Controlled HTTP against the REAL registered stripe processor. POST
    // attempts 1-2 fail (first operation unknown); attempt 3+ succeed.
    // The transfer-group listing finds nothing until the test says so.
    Http::preventStrayRequests();

    $posts = 0;
    $listed = [];

    Http::fake([
        'api.stripe.com/v1/transfers*' => function ($request) use (&$posts, &$listed) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => $listed], 200);
            }

            $posts++;

            return $posts <= 2
                ? Http::response(['error' => ['code' => 'api_error']], 500)
                : Http::response(['id' => 'tr_resend_1'], 200);
        },
    ]);
    config()->set('affiliates.payouts.stripe.secret_key', 'sk_test_fixture');

    expect(app(RewardPayoutProcessorCatalog::class)->isSupported('stripe_connect'))->toBeTrue();

    [$awardOne, $memberOne, $readerOne] = financeHeldCashAward($program, $version, 'src-fin-stripe-1');

    expect(fn () => VerifyRewardPayeeAction::run(
        'user', $memberOne, 'stripe_connect', 'not-an-account', 'not-an-account',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'refused by [stripe_connect]');

    $payeeOne = VerifyRewardPayeeAction::run(
        'user', $memberOne, 'stripe_connect', 'acct_frozen_one', 'acct_fro…',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approvedOne = financeApprove($awardOne, $readerOne, $staff, '2026-06-17T00:00:00Z');

    $requestOne = RequestRewardPayoutAction::run(
        $approvedOne, $payeeOne, 'stripe_connect', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    // Program-paid gross-up under the installed schedule:
    // 527 gross - 27 fee = 500 promised net.
    expect($requestOne->fee_minor)->toBe(27)
        ->and($requestOne->payee_snapshot['gross_minor'])->toBe(527)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 527, 'net_spent' => 0, 'available' => 9473]);

    $requestOne = ApproveRewardPayoutAction::run(
        $requestOne, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );

    $requestOne = SubmitRewardPayoutAction::run(
        $requestOne, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($requestOne->status)->toBe(RewardPayoutRequestStatus::Unknown)
        ->and($requestOne->provider_reference)->toBeNull();

    // Cancel without a provider reference cannot confirm: the request
    // stays unknown with its reservation intact (no reversal HTTP).
    expect(fn () => CancelRewardPayoutAction::run(
        $requestOne, 'user', $staff, 'staff', 'give-up', CarbonImmutable::parse('2026-06-18T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'did not confirm cancellation');

    // Retry: reconciliation finds no transfer for the operation group,
    // so the installed rail re-sends with the SAME idempotency key and
    // completes. Spend lands exactly once.
    $requestOne = SubmitRewardPayoutAction::run(
        $requestOne, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T02:00:00Z'),
    );

    expect($requestOne->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and($requestOne->provider_reference)->toBe('tr_resend_1')
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 527, 'available' => 9473]);

    $fulfilledOne = (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $readerOne))->handle(
        $approvedOne, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'paid', null, null, CarbonImmutable::parse('2026-06-18T03:00:00Z'),
    );

    expect($fulfilledOne->status)->toBe(RewardAwardStatus::Fulfilled);

    $operationId = AffiliatePayout::query()->whereKey((string) $requestOne->affiliate_payout_id)->firstOrFail()->operation->id;

    $transferPosts = Http::recorded(
        fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1/transfers')
    )->values();

    expect($transferPosts)->toHaveCount(3);

    foreach ($transferPosts as $pair) {
        expect($pair[0]->header('Idempotency-Key'))->toBe([(string) $operationId])
            ->and($pair[0]->data())->toBe([
                'amount' => 500,
                'currency' => 'myr',
                'destination' => 'acct_frozen_one',
                'transfer_group' => (string) $operationId,
                'metadata' => [
                    'payout_id' => (string) $requestOne->affiliate_payout_id,
                    'operation_id' => (string) $operationId,
                ],
            ]);
    }

    // Second operation: unknown first, then the provider LATE-completes
    // (the transfer exists under the operation group) and reconcile
    // accepts it, spending exactly once.
    [$awardTwo, $memberTwo, $readerTwo] = financeHeldCashAward($program, $version, 'src-fin-stripe-2');

    $payeeTwo = VerifyRewardPayeeAction::run(
        'user', $memberTwo, 'stripe_connect', 'acct_frozen_two', 'acct_fro…',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approvedTwo = financeApprove($awardTwo, $readerTwo, $staff, '2026-06-17T00:00:00Z');

    $requestTwo = RequestRewardPayoutAction::run(
        $approvedTwo, $payeeTwo, 'stripe_connect', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );
    $requestTwo = ApproveRewardPayoutAction::run(
        $requestTwo, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );

    // From here every POST succeeds: a clean second operation confirms
    // on first send and spends exactly once.
    $requestTwo = SubmitRewardPayoutAction::run(
        $requestTwo, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T04:00:00Z'),
    );

    expect($requestTwo->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 1054, 'available' => 8946]);

    // Third operation proves the late-completion path through reconcile:
    // fail its send, then publish the transfer, then reconcile.
    [$awardThree, $memberThree, $readerThree] = financeHeldCashAward($program, $version, 'src-fin-stripe-3');

    $payeeThree = VerifyRewardPayeeAction::run(
        'user', $memberThree, 'stripe_connect', 'acct_frozen_three', 'acct_fro…',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approvedThree = financeApprove($awardThree, $readerThree, $staff, '2026-06-17T00:00:00Z');

    $requestThree = RequestRewardPayoutAction::run(
        $approvedThree, $payeeThree, 'stripe_connect', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );
    $requestThree = ApproveRewardPayoutAction::run(
        $requestThree, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );

    // Fail this send, then publish the late transfer for reconcile.
    $posts = 0;

    $requestThree = SubmitRewardPayoutAction::run(
        $requestThree, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T05:00:00Z'),
    );

    expect($requestThree->status)->toBe(RewardPayoutRequestStatus::Unknown);

    $listed = [['id' => 'tr_late_1']];

    $requestThree = ReconcileRewardPayoutAction::run(
        $requestThree, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($requestThree->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 1581, 'available' => 8419]);
});

it('freezes the payout destination at request time against later payee changes', function () {
    [$program, $version] = financeProgram('fin-frozen');
    [$award, $memberId, $reader] = financeHeldCashAward($program, $version, 'src-fin-frozen-1');
    [$staff, $verifier, $requester, $approver] = financeStaff();

    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'pp-test-token'], 200),
        'api-m.sandbox.paypal.com/v1/payments/payouts*' => Http::response(['batch_header' => ['payout_batch_id' => 'BATCH-FROZEN-1']], 201),
    ]);
    config()->set('affiliates.payouts.paypal.client_id', 'test-id');
    config()->set('affiliates.payouts.paypal.client_secret', 'test-secret');

    $original = VerifyRewardPayeeAction::run(
        'user', $memberId, 'paypal', 'original@example.test', 'original@example.test',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = financeApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');

    $request = RequestRewardPayoutAction::run(
        $approved, $original, 'paypal', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    // The payee changes AFTER the request: the new row supersedes, but
    // the frozen operation still pays the original destination.
    VerifyRewardPayeeAction::run(
        'user', $memberId, 'paypal', 'changed@example.test', 'changed@example.test',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-17T00:30:00Z'),
    );

    $request = ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted);

    Http::assertSent(fn ($sent) => ($sent->data()['items'][0]['receiver'] ?? null) === 'original@example.test');
    Http::assertNotSent(fn ($sent) => ($sent->data()['items'][0]['receiver'] ?? null) === 'changed@example.test');

    // The bound method carries the frozen payee + destination, and it is
    // the serving default; no earlier match was reused.
    $bound = AffiliatePayoutMethod::query()->where('is_default', true)->firstOrFail();

    expect($bound->details['payee_id'])->toBe((string) $original->getKey())
        ->and($bound->details['email'])->toBe('original@example.test')
        ->and($request->refresh()->evidence['bound_method']['masked']['email'])->not->toBeNull();
});

it('reconciles the original operation after a payee change follows the send', function () {
    [$program, $version] = financeProgram('fin-sendfirst');
    [$award, $memberId, $reader] = financeHeldCashAward($program, $version, 'src-fin-sendfirst-1');
    [$staff, $verifier, $requester, $approver] = financeStaff();

    Http::preventStrayRequests();

    $listed = [];

    Http::fake([
        'api.stripe.com/v1/transfers*' => function ($request) use (&$listed) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => $listed], 200);
            }

            return Http::response(['error' => ['code' => 'api_error']], 500);
        },
    ]);
    config()->set('affiliates.payouts.stripe.secret_key', 'sk_test_fixture');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'stripe_connect', 'acct_before_change', 'acct_bef…',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = financeApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');

    $request = RequestRewardPayoutAction::run(
        $approved, $payee, 'stripe_connect', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );
    $request = ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Unknown)
        ->and($request->provider_reference)->toBeNull();

    // The payee changes AFTER the send: reconciliation still settles the
    // ORIGINAL operation and destination, never the current payee row.
    VerifyRewardPayeeAction::run(
        'user', $memberId, 'stripe_connect', 'acct_after_change', 'acct_aft…',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-18T01:00:00Z'),
    );

    $listed = [['id' => 'tr_after_change_1']];

    $request = ReconcileRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and($request->payee_snapshot['account_ref'])->toBe('acct_before_change')
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 527, 'available' => 9473]);

    // Every attempted send carried the frozen destination, not the new one.
    Http::assertSent(fn ($sent) => ($sent->data()['destination'] ?? null) === 'acct_before_change');
    Http::assertNotSent(fn ($sent) => ($sent->data()['destination'] ?? null) === 'acct_after_change');
});
