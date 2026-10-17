<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\AdvanceRewardAppealAction;
use AIArmada\Rewards\Actions\ApproveRewardPayoutAction;
use AIArmada\Rewards\Actions\ConfirmRewardPayoutAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\OpenRewardAppealAction;
use AIArmada\Rewards\Actions\OpenRewardRecoveryAction;
use AIArmada\Rewards\Actions\ReconcileRewardPayoutAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\RequestRewardPayoutAction;
use AIArmada\Rewards\Actions\ReverseRewardFulfillmentAction;
use AIArmada\Rewards\Actions\SettleRewardRecoveryAction;
use AIArmada\Rewards\Actions\SubmitRewardPayoutAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Actions\VerifyRewardPayeeAction;
use AIArmada\Rewards\Contracts\RewardFinancialReadinessChecker;
use AIArmada\Rewards\Enums\RewardAppealStatus;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardBudgetMovementKind;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardEvaluationOutcome;
use AIArmada\Rewards\Enums\RewardPayoutRequestStatus;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Enums\RewardRecoveryStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBudgetMovement;
use AIArmada\Rewards\Models\RewardFundingEntry;
use AIArmada\Rewards\Models\RewardFundingSource;
use AIArmada\Rewards\Models\RewardRecovery;
use AIArmada\Rewards\Support\RewardBudgetLedger;
use App\Models\User;
use App\Support\Rewards\RewardMemberPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

// R5 proof: immutable funding/allocation authority (one deposit cannot be
// counted twice across programs), reservation at the held-obligation
// boundary, own-obligation-aware gates, conversion without double
// counting, unknown-held reserves, spend-bounded recoveries, and
// truthful member funds wording. Real actions throughout.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('affiliates.payouts.reward_award_settlement.enabled', true);
});

function faStaff(): array
{
    return [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
}

function faProgram(string $prefix, int $fundedMinor, string $currency = 'MYR', ?int $sourceTotal = null): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, RewardProgramMode::Cash);
    [$funder] = faStaff();

    RecordRewardFundingSourceAction::run(
        'TREASURY-'.$prefix, $currency, $sourceTotal ?? $fundedMinor, 'user', $funder, 'staff',
        ['document' => 'treasury-'.$prefix], CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    RecordRewardFundingAction::run(
        $program, $currency, $fundedMinor, 'TREASURY-'.$prefix,
        'user', $funder, 'staff', ['document' => 'treasury-'.$prefix],
        CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    return [$program, $version];
}

function faHeld($program, $version, string $source, ?string $memberId = null): array
{
    $memberId ??= (string) Str::uuid();

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

function faApprove(RewardAward $award, $reader, string $staff, string $at): RewardAward
{
    return (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'policy-ok', null, null, CarbonImmutable::parse($at),
    );
}

it('allocates one deposit across two programs and refuses overallocation per currency', function () {
    [$programA] = faProgram('fa-split-a', 7000, 'MYR', 10000);
    [$programB] = RewardLedgerFixtures::seedVersionedProgram('fa-split-b', RewardProgramMode::Cash);
    [$funder] = faStaff();

    // The SAME confirmed 10000 deposit feeds both programs: 7000 + 4000
    // would overallocate, so the second allocation refuses.
    expect(fn () => RecordRewardFundingAction::run(
        $programB, 'MYR', 4000, 'TREASURY-fa-split-a',
        'user', $funder, 'staff', null, CarbonImmutable::parse('2026-06-03T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'refusing overallocation');

    RecordRewardFundingAction::run(
        $programB, 'MYR', 3000, 'TREASURY-fa-split-a',
        'user', $funder, 'staff', null, CarbonImmutable::parse('2026-06-03T00:00:00Z'),
    );

    TransitionRewardProgramStatusAction::run($programB, RewardProgramStatus::Ready);
    $programB = TransitionRewardProgramStatusAction::run($programB, RewardProgramStatus::Active);

    expect(RewardBudgetLedger::balances((string) $programA->getKey(), 'MYR'))
        ->toBe(['funded' => 7000, 'reserved' => 0, 'net_spent' => 0, 'available' => 7000])
        ->and(RewardBudgetLedger::balances((string) $programB->getKey(), 'MYR'))
        ->toBe(['funded' => 3000, 'reserved' => 0, 'net_spent' => 0, 'available' => 3000]);

    // The source row is the immutable ceiling: still exactly 10000, and
    // no currency is ever summed with another (a USD source is separate).
    expect(RewardFundingSource::query()->where('source_ref', 'TREASURY-fa-split-a')->value('amount_minor'))
        ->toBe(10000);

    RecordRewardFundingSourceAction::run(
        'TREASURY-fa-split-usd', 'USD', 5000, 'user', $funder, 'staff',
        null, CarbonImmutable::parse('2026-06-03T00:00:00Z'),
    );
    RecordRewardFundingAction::run(
        $programB, 'USD', 5000, 'TREASURY-fa-split-usd',
        'user', $funder, 'staff', null, CarbonImmutable::parse('2026-06-03T00:00:00Z'),
    );

    expect(RewardBudgetLedger::balances((string) $programB->getKey(), 'USD'))
        ->toBe(['funded' => 5000, 'reserved' => 0, 'net_spent' => 0, 'available' => 5000])
        ->and(RewardBudgetLedger::balances((string) $programB->getKey(), 'MYR')['funded'])->toBe(3000);
});

it('refuses allocation without a recorded source and replays retries on same terms', function () {
    [$program] = faProgram('fa-nosrc', 1000);
    [$funder] = faStaff();

    expect(fn () => RecordRewardFundingAction::run(
        $program, 'MYR', 500, 'TREASURY-ghost',
        'user', $funder, 'staff', null, CarbonImmutable::parse('2026-06-03T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Unknown confirmed funding source');

    // Ambiguous operator retry: identical terms replay the same row
    // instead of double-counting; conflicting terms refuse.
    $replayed = RecordRewardFundingAction::run(
        $program, 'MYR', 1000, 'TREASURY-fa-nosrc',
        'user', $funder, 'staff', ['document' => 'treasury-fa-nosrc'],
        CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    $entries = RewardFundingEntry::query()
        ->where('program_id', (string) $program->getKey())
        ->where('source_ref', 'TREASURY-fa-nosrc')
        ->get();

    expect($entries)->toHaveCount(1)
        ->and((string) $replayed->getKey())->toBe((string) $entries->first()->getKey())
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['funded'])->toBe(1000);

    expect(fn () => RecordRewardFundingAction::run(
        $program, 'MYR', 600, 'TREASURY-fa-nosrc',
        'user', $funder, 'staff', null, CarbonImmutable::parse('2026-06-03T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'already bound to different terms');
});

it('reserves earned held awards at the evaluation boundary', function () {
    [$program, $version] = faProgram('fa-held', 10000);

    [$awardOne] = faHeld($program, $version, 'src-fa-held-1');

    expect(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 500, 'net_spent' => 0, 'available' => 9500])
        ->and($awardOne->refresh()->obligation_epoch)->toBe(0)
        ->and($awardOne->obligated_minor)->toBe(500);

    [$awardTwo] = faHeld($program, $version, 'src-fa-held-2');

    // Two earned obligations both reserve; nothing waits for request.
    expect(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 1000, 'net_spent' => 0, 'available' => 9000])
        ->and($awardTwo->refresh()->obligated_minor)->toBe(500);
});

it('holds earning harmless for shortfall while the approval gate waits for funding', function () {
    // Source 10000, program allocated only 400: the 500 award still earns.
    [$program, $version] = faProgram('fa-short', 400, 'MYR', 10000);

    [$award, , $reader] = faHeld($program, $version, 'src-fa-short-1');
    [$staff] = faStaff();

    expect(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 400, 'reserved' => 500, 'net_spent' => 0, 'available' => -100]);

    // Approval refuses while the program cannot cover the debt...
    $payee = VerifyRewardPayeeAction::run(
        'user', (string) $award->recipient_id, 'bank_transfer', 'ACCT-SHORT', 'Bank ••SH',
        'user', (string) Str::uuid(), 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    expect(fn () => faApprove($award, $reader, $staff, '2026-06-17T00:00:00Z'))
        ->toThrow(InvalidArgumentException::class, 'insufficient-confirmed-funding');

    // ...and passes once the treasury tops the allocation up. The new
    // ref needs its own confirmed source first.
    expect(fn () => RecordRewardFundingAction::run(
        $program, 'MYR', 9600, 'TREASURY-fa-short-topup', 'user', $staff, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Unknown confirmed funding source');

    RecordRewardFundingSourceAction::run(
        'TREASURY-fa-short-topup', 'MYR', 9600, 'user', $staff, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    RecordRewardFundingAction::run(
        $program, 'MYR', 9600, 'TREASURY-fa-short-topup', 'user', $staff, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    $approved = faApprove($award, $reader, $staff, '2026-06-17T02:00:00Z');

    expect($approved->status)->toBe(RewardAwardStatus::Approved);
});

it('releases the held obligation on rejection with replay-safe double release', function () {
    [$program, $version] = faProgram('fa-rej', 10000);
    [$award, , $reader] = faHeld($program, $version, 'src-fa-rej-1');
    [$staff] = faStaff();

    expect(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['reserved'])->toBe(500);

    $rejected = (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $award, RewardAwardStatus::Rejected, 'user', $staff, 'staff', 'not-qualified',
        null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    expect($rejected->status)->toBe(RewardAwardStatus::Rejected)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000]);

    // A second rejection is a replay no-op, never a double credit.
    $again = (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $rejected, RewardAwardStatus::Rejected, 'user', $staff, 'staff', 'not-qualified',
        null, null, CarbonImmutable::parse('2026-06-16T01:00:00Z'),
    );

    expect($again->status)->toBe(RewardAwardStatus::Rejected)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000]);
});

it('re-obligates an overturned rejection under a fresh epoch', function () {
    [$program, $version] = faProgram('fa-over', 10000);
    [$staff, $reviewer] = faStaff();

    $memberId = (string) Str::uuid();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    // Unverified at earning time: the award rejects at evaluation with
    // no obligation and no claim.
    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact(
        'src-fa-over-1', $memberId, '2026-06-12T00:00:00Z',
        'event_checkin', ['verification' => RewardVerificationLevel::Unverified],
    ));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Cash, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-fa-over-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect($result->outcome)->toBe(RewardEvaluationOutcome::Rejected)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['reserved'])->toBe(0);

    $award = $result->award->refresh();

    VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-OVER', 'Bank ••OV',
        'user', (string) Str::uuid(), 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    // The source verifies later; merit review overturns against
    // now-qualified evidence and the revived debt reserves under epoch 1.
    $reader->seed(RewardLedgerFixtures::fact('src-fa-over-1', $memberId));

    $opened = OpenRewardAppealAction::run(
        $award, 'user', $memberId, 'member', 'verification-completed', null, CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );
    AdvanceRewardAppealAction::run(
        $opened, RewardAppealStatus::InReview, 'user', $reviewer, 'admin', 'taking-case',
        null, CarbonImmutable::parse('2026-06-18T01:00:00Z'),
    );
    (new AdvanceRewardAppealAction($reader))->handle(
        $opened, RewardAppealStatus::Overturned, 'user', $reviewer, 'admin', 'evidence-now-qualified',
        ['review' => 'verified'], CarbonImmutable::parse('2026-06-18T02:00:00Z'),
    );

    $award = $award->refresh();

    expect($award->status)->toBe(RewardAwardStatus::Approved)
        ->and($award->obligation_epoch)->toBe(1)
        ->and($award->obligated_minor)->toBe(500)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 500, 'net_spent' => 0, 'available' => 9500]);
});

it('converts the held obligation into the payout reserve without double counting', function () {
    [$program, $version] = faProgram('fa-conv', 10000);
    [$award, $memberId, $reader] = faHeld($program, $version, 'src-fa-conv-1');
    [$staff, $verifier, $requester, $approver] = faStaff();

    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'pp-test-token'], 200),
        'api-m.sandbox.paypal.com/v1/payments/payouts' => Http::response(['batch_header' => ['payout_batch_id' => 'BATCH-CONV-1']], 201),
        'api-m.sandbox.paypal.com/v1/payments/payouts/*' => Http::response(['batch_header' => ['batch_status' => 'SUCCESS']], 200),
    ]);
    config()->set('affiliates.payouts.paypal.client_id', 'test-id');
    config()->set('affiliates.payouts.paypal.client_secret', 'test-secret');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'paypal', 'conv@example.test', 'conv@example.test',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = faApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');

    // Held: 500 obligated. Request: 500 retired, 511 gross reserved —
    // the ledger prices only the incremental 11, never 500 + 511.
    $request = RequestRewardPayoutAction::run(
        $approved, $payee, 'paypal', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    expect(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 511, 'net_spent' => 0, 'available' => 9489]);

    $request = ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );
    $request = ReconcileRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T01:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Confirmed)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 511, 'available' => 9489]);
});

it('keeps unknown payouts reserved and refuses resubmission until reconciliation', function () {
    [$program, $version] = faProgram('fa-unk', 10000);
    [$award, $memberId, $reader] = faHeld($program, $version, 'src-fa-unk-1');
    [$staff, $verifier, $requester, $approver] = faStaff();

    Http::preventStrayRequests();
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'pp-test-token'], 200),
        'api-m.sandbox.paypal.com/v1/payments/payouts*' => Http::response(['name' => 'SERVER_ERROR'], 500),
    ]);
    config()->set('affiliates.payouts.paypal.client_id', 'test-id');
    config()->set('affiliates.payouts.paypal.client_secret', 'test-secret');

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'paypal', 'unk@example.test', 'unk@example.test',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = faApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
    $request = RequestRewardPayoutAction::run(
        $approved, $payee, 'paypal', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );
    $request = ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Unknown)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 511, 'net_spent' => 0, 'available' => 9489]);

    // A resubmit while unknown retries the SAME authorization: no new
    // budget reserve lands, the single attempt-1 reserve stands.
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T01:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Unknown)
        ->and(RewardBudgetMovement::query()
            ->where('program_id', (string) $program->getKey())
            ->where('currency', 'MYR')
            ->where('kind', RewardBudgetMovementKind::Reserve->value)
            ->count())->toBe(2)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['reserved'])->toBe(511);

    // Unprovable status keeps the reservation and the operation intact.
    $request = ReconcileRewardPayoutAction::run(
        $request, 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-18T02:00:00Z'),
    );

    expect($request->status)->toBe(RewardPayoutRequestStatus::Unknown)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR')['reserved'])->toBe(511);
});

it('bounds confirmed recoveries to actual spend and refuses to mint budget', function () {
    [$program, $version] = faProgram('fa-rec', 10000);
    [$award, $memberId, $reader] = faHeld($program, $version, 'src-fa-rec-1');
    [$staff, $verifier, $requester, $approver] = faStaff();
    $confirmer = (string) Str::uuid();

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-REC', 'Bank ••RC',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = faApprove($award, $reader, $staff, '2026-06-17T00:00:00Z');
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
    $request = ConfirmRewardPayoutAction::run(
        $request, ['bank_reference' => 'BANK-FA-REC'], 'user', $confirmer, 'staff',
        CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    $fulfilled = (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $approved, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'paid',
        null, null, CarbonImmutable::parse('2026-06-19T01:00:00Z'),
    );

    expect(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 500, 'available' => 9500]);

    $outcome = ReverseRewardFulfillmentAction::run(
        $fulfilled, 'user', $staff, 'staff', 'source-invalid', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'),
    );

    $recovery = RewardRecovery::query()->whereKey($outcome['recovery_ids'][0])->firstOrFail();

    $settled = SettleRewardRecoveryAction::run(
        $recovery, ['recovery_proof' => 'bank-credit-FA-REC'], 'user', $staff, 'staff',
        CarbonImmutable::parse('2026-06-21T00:00:00Z'),
    );

    expect($settled->status)->toBe(RewardRecoveryStatus::Settled)
        ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
        ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000]);

    // Nothing remains spent: a second recovery against the same spend
    // would mint budget, so it refuses.
    $second = OpenRewardRecoveryAction::run(
        $fulfilled->refresh(), 'dispute', null, null, 500, 'MYR', null, null,
        'user', $staff, 'staff', 'double-claim', null, CarbonImmutable::parse('2026-06-22T00:00:00Z'),
    );

    expect(fn () => SettleRewardRecoveryAction::run(
        $second, ['recovery_proof' => 'bank-credit-FAKE'], 'user', $staff, 'staff',
        CarbonImmutable::parse('2026-06-23T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'mint budget');
});

it('labels member payout funds truthfully across every status', function () {
    $confirmedAt = '2026-06-19T00:00:00+00:00';

    expect(RewardMemberPresenter::payoutFundsState('confirmed', $confirmedAt))
        ->toBe(['state' => 'paid', 'label' => 'Paid', 'paid_at' => $confirmedAt])
        ->and(RewardMemberPresenter::payoutFundsState('submitted', null)['state'])->toBe('processing')
        ->and(RewardMemberPresenter::payoutFundsState('submitted', null)['paid_at'])->toBeNull()
        ->and(RewardMemberPresenter::payoutFundsState('requested', null)['state'])->toBe('processing')
        ->and(RewardMemberPresenter::payoutFundsState('approved', null)['state'])->toBe('processing')
        ->and(RewardMemberPresenter::payoutFundsState('unknown', null)['state'])->toBe('unknown')
        ->and(RewardMemberPresenter::payoutFundsState('failed', null)['state'])->toBe('failed')
        ->and(RewardMemberPresenter::payoutFundsState('cancelled', null)['state'])->toBe('cancelled');

    // The member endpoint carries the same block: submitted renders
    // processing, never paid.
    [$program, $version] = faProgram('fa-words', 10000);
    $member = User::factory()->create();
    $memberId = (string) $member->getKey();
    [$award] = faHeld($program, $version, 'src-fa-words-1', $memberId);
    [$staff, $verifier, $requester, $approver] = faStaff();

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-WORDS', 'Bank ••WD',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-fa-words-1', $memberId));

    $approved = faApprove($award->refresh(), $reader, $staff, '2026-06-17T00:00:00Z');
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

    expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted);

    $this->actingAs($member);
    $response = $this->getJson(route('api.rewards.payout.show', ['award' => (string) $award->getKey()]));

    $response->assertOk();
    $funds = $response->json('data.funds');

    expect($response->json('data.status'))->toBe('submitted')
        ->and($funds['state'])->toBe('processing')
        ->and($funds['paid_at'])->toBeNull();
});
