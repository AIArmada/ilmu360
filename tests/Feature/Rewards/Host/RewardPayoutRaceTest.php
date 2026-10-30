<?php

declare(strict_types=1);

// R4 real-driver proof: independent payout workers race the same reward
// fixtures on the native manual rail. The per-request send mutex,
// pre-send re-verification, and stable manual operation keys make every
// interleaving coherent: exactly one terminal outcome and exact budget
// accounting. Timing is never asserted.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Rewards\Actions\ApproveRewardPayoutAction;
use AIArmada\Rewards\Actions\ConfirmRewardPayoutAction;
use AIArmada\Rewards\Actions\CreateRewardProgramAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\RequestRewardPayoutAction;
use AIArmada\Rewards\Actions\SubmitRewardPayoutAction;
use AIArmada\Rewards\Actions\VerifyRewardPayeeAction;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardBudgetMovementKind;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardPayoutRequestStatus;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBudgetMovement;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardRecovery;
use AIArmada\Rewards\Support\RewardBudgetLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;
use Tests\Feature\ShareTracking\ShareMetricRaceHarness;
use Tests\TestCase;

function lcrProgram(string $family): RewardProgram
{
    // Both the parent and every worker opt into the native manual rail;
    // the shipped host binds the null executor with an empty catalog.
    RewardLedgerFixtures::useManualPayouts();

    // Mute the notification side channel in the setup process too (the
    // workers do the same): it is not the raced boundary.
    config(['affiliates.notifications.enabled' => false]);

    $program = CreateRewardProgramAction::run(
        $family, 'Race program', RewardProgramMode::Cash, null, null, 'race-test',
    );

    $funder = (string) Str::uuid();

    RecordRewardFundingSourceAction::run(
        'TREASURY-'.$family, 'MYR', 10000, 'user', $funder, 'staff',
        ['document' => 'treasury-1'], CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    RecordRewardFundingAction::run(
        $program, 'MYR', 10000, 'TREASURY-'.$family,
        'user', $funder, 'staff', ['document' => 'treasury-1'],
        CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    return $program->refresh();
}

function lcrAward(RewardProgram $program, string $memberId, string $source): RewardAward
{
    // Direct fixture row: the payout actions under test (submit, cancel,
    // confirm, reverse) run for real against it. Entitlement evaluation
    // is not the boundary under test here.
    return RewardAward::query()->create([
        'program_id' => $program->getKey(),
        'recipient_type' => 'user',
        'recipient_id' => $memberId,
        'beneficiary_role' => RewardBeneficiaryRole::Recipient->value,
        'source_business_key' => $source,
        'family_key' => (string) $program->family_key,
        'component' => RewardComponent::Cash->value,
        'status' => RewardAwardStatus::Approved->value,
        'amount_snapshot' => ['minor_units' => 500, 'currency' => 'MYR'],
        'policy_snapshot' => ['fixture' => true],
        'consent_snapshot' => ['fixture' => true],
        'source_snapshot' => ['business_key' => $source],
        'held_at' => CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        'approved_at' => CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    ]);
}

/**
 * @return array{0: RewardPayoutRequest, 1: array{staff: string, verifier: string, requester: string, approver: string}}
 */
function lcrRequest(RewardAward $award, string $memberId, string $method, string $ref): array
{
    $staff = (string) Str::uuid();
    $verifier = (string) Str::uuid();
    $requester = (string) Str::uuid();
    $approver = (string) Str::uuid();

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, $method, $ref, $ref,
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $request = RequestRewardPayoutAction::run(
        $award, $payee, $method, 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    $request = ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );

    return [$request, compact('staff', 'verifier', 'requester', 'approver')];
}

/**
 * @param  array{driver: string, database: string, app_url: string, mode: string, payload: array<string, mixed>}  $job
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function lcrRace(array $job): array
{
    $job['worker'] = __DIR__.'/RewardPayoutRaceWorker.php';

    return ShareMetricRaceHarness::runRace($job);
}

function lcrSubmitCancelCase(TestCase $testCase): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker('lcrsc');

    try {
        $program = lcrProgram('race-sc');
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-sc-1');
        [$request, $actors] = lcrRequest($award, $memberId, 'bank_transfer', 'ACCT-RACE-SC');

        [$first, $second] = lcrRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'payout',
            'payload' => [
                'request_id' => (string) $request->getKey(),
                'actor' => ['type' => 'user', 'id' => $actors['staff'], 'role' => 'staff'],
                'reason' => 'race-decision',
                'decided_at' => '2026-06-18T00:00:00Z',
                'slots' => [['op' => 'submit'], ['op' => 'cancel']],
            ],
        ]);

        $final = $request->refresh()->status;
        $balances = RewardBudgetLedger::balances((string) $program->getKey(), 'MYR');

        // Exactly one terminal outcome, coherent with the ledger: either
        // the manual submission won (Submitted + 500 reserved, cancel
        // released it) or the cancel won (Cancelled + released, submit
        // aborted). Never a spend on the manual rail.
        expect(in_array($final, [RewardPayoutRequestStatus::Submitted, RewardPayoutRequestStatus::Cancelled], true))->toBeTrue()
            ->and(RewardBudgetMovement::query()
                ->where('program_id', (string) $program->getKey())
                ->where('kind', RewardBudgetMovementKind::Spend->value)
                ->count())->toBe(0);

        if ($final === RewardPayoutRequestStatus::Submitted) {
            expect($balances)->toBe(['funded' => 10000, 'reserved' => 500, 'net_spent' => 0, 'available' => 9500])
                ->and($request->refresh()->external_payout_id)
                ->toBe('manual:reward-payout:'.$award->getKey());
        } else {
            expect($balances)->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000])
                ->and(collect([$first, $second])->pluck('outcome')->all())->toContain('aborted');
        }
    } finally {
        ShareMetricRaceHarness::teardown($database, $previous);
    }
}

function lcrDoubleSubmitCase(TestCase $testCase): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker('lcrss');

    try {
        $program = lcrProgram('race-ss');
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-ss-1');
        [$request, $actors] = lcrRequest($award, $memberId, 'bank_transfer', 'ACCT-RACE-SS');

        [$first, $second] = lcrRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'payout',
            'payload' => [
                'request_id' => (string) $request->getKey(),
                'actor' => ['type' => 'user', 'id' => $actors['staff'], 'role' => 'staff'],
                'decided_at' => '2026-06-18T00:00:00Z',
                'slots' => [['op' => 'submit'], ['op' => 'submit']],
            ],
        ]);

        // One successful manual submission, one stable operation id, one
        // reservation; the loser aborts cleanly instead of re-recording.
        expect($request->refresh()->status)->toBe(RewardPayoutRequestStatus::Submitted)
            ->and($request->external_payout_id)->toBe('manual:reward-payout:'.$award->getKey())
            ->and(collect([$first, $second])->where('outcome', 'done')->count())->toBe(1)
            ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
            ->toBe(['funded' => 10000, 'reserved' => 500, 'net_spent' => 0, 'available' => 9500])
            ->and(RewardBudgetMovement::query()->where('kind', 'spend')->count())->toBe(0);
    } finally {
        ShareMetricRaceHarness::teardown($database, $previous);
    }
}

function lcrDoubleReverseCase(TestCase $testCase): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker('lcrrr');

    try {
        $program = lcrProgram('race-rr');
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-rr-1');
        [$request, $actors] = lcrRequest($award, $memberId, 'bank_transfer', 'ACCT-RACE-RR');
        $confirmer = (string) Str::uuid();

        $request = SubmitRewardPayoutAction::run(
            $request, 'user', $actors['staff'], 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
        );
        $request = ConfirmRewardPayoutAction::run(
            $request,
            ['bank_reference' => 'BANK-RACE-RR', 'transferred_at' => '2026-06-19T00:00:00Z'],
            'user', $confirmer, 'staff',
            CarbonImmutable::parse('2026-06-19T00:00:00Z'),
        );

        // Constructed Fulfilled pre-state (machine-valid): the payout leg
        // above ran for real and paid-only fulfillment is proven
        // sequentially. The raced boundary here is the reversal itself.
        $award->forceFill([
            'status' => RewardAwardStatus::Fulfilled->value,
            'fulfilled_at' => CarbonImmutable::parse('2026-06-19T01:00:00Z'),
        ])->save();

        $fulfilled = $award->refresh();

        expect($fulfilled->status)->toBe(RewardAwardStatus::Fulfilled);

        [$first, $second] = lcrRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'payout',
            'payload' => [
                'award_id' => (string) $award->getKey(),
                'actor' => ['type' => 'user', 'id' => $actors['staff'], 'role' => 'staff'],
                'reason' => 'source-invalid',
                'decided_at' => '2026-06-20T00:00:00Z',
                'slots' => [['op' => 'reverse'], ['op' => 'reverse']],
            ],
        ]);

        // One reversal wins with a single receivable; the loser refuses.
        expect($award->refresh()->status)->toBe(RewardAwardStatus::Reversed)
            ->and(collect([$first, $second])->where('outcome', 'done')->count())->toBe(1)
            ->and(RewardRecovery::query()->where('award_id', $award->getKey())->count())->toBe(1);
    } finally {
        ShareMetricRaceHarness::teardown($database, $previous);
    }
}

function lcrDoubleCancelCase(TestCase $testCase): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker('lcrcc');

    try {
        $program = lcrProgram('race-cc');
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-cc-1');

        $staff = (string) Str::uuid();
        $verifier = (string) Str::uuid();
        $requester = (string) Str::uuid();

        $payee = VerifyRewardPayeeAction::run(
            'user', $memberId, 'bank_transfer', 'ACCT-RACE-CC', 'ACCT-RACE-CC',
            'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
        );

        // Requested, never approved: no manual submission exists yet.
        $request = RequestRewardPayoutAction::run(
            $award, $payee, 'bank_transfer', 'program', 'user', $requester, 'staff',
            null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
        );

        [$first, $second] = lcrRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'payout',
            'payload' => [
                'request_id' => (string) $request->getKey(),
                'actor' => ['type' => 'user', 'id' => $staff, 'role' => 'staff'],
                'reason' => 'changed-mind',
                'decided_at' => '2026-06-17T02:00:00Z',
                'slots' => [['op' => 'cancel'], ['op' => 'cancel']],
            ],
        ]);

        // One cancellation wins; the attempt releases exactly once.
        expect($request->refresh()->status)->toBe(RewardPayoutRequestStatus::Cancelled)
            ->and(collect([$first, $second])->where('outcome', 'done')->count())->toBe(1)
            ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
            ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000])
            ->and(RewardBudgetMovement::query()->where('kind', 'release')->count())->toBe(1);
    } finally {
        ShareMetricRaceHarness::teardown($database, $previous);
    }
}

function lcrDoubleConfirmCase(TestCase $testCase): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker('lcrlc');

    try {
        $program = lcrProgram('race-lc');
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-lc-1');
        [$request, $actors] = lcrRequest($award, $memberId, 'bank_transfer', 'ACCT-RACE-LC');

        // Main process drives the manual request to Submitted; both
        // workers then race the independent confirmation.
        $request = SubmitRewardPayoutAction::run(
            $request, 'user', $actors['staff'], 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
        );

        expect($request->status)->toBe(RewardPayoutRequestStatus::Submitted);

        [$first, $second] = lcrRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'payout',
            'payload' => [
                'request_id' => (string) $request->getKey(),
                'actor' => ['type' => 'user', 'id' => $actors['staff'], 'role' => 'staff'],
                'decided_at' => '2026-06-19T00:00:00Z',
                'slots' => [['op' => 'confirm'], ['op' => 'confirm']],
            ],
        ]);

        // Independent confirmation settles both ledgers exactly once; the
        // loser observes the confirmation instead of re-spending.
        expect($request->refresh()->status)->toBe(RewardPayoutRequestStatus::Confirmed)
            ->and(collect([$first, $second])->where('outcome', 'done')->count())->toBe(1)
            ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
            ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 500, 'available' => 9500])
            ->and(RewardBudgetMovement::query()->where('kind', 'spend')->count())->toBe(1);
    } finally {
        ShareMetricRaceHarness::teardown($database, $previous);
    }
}

$label = 'isolated PostgreSQL';
it("keeps a raced submit and cancel coherent on {$label}", function (): void {
    lcrSubmitCancelCase($this);
});

it("submits exactly once under a raced double submit on {$label}", function (): void {
    lcrDoubleSubmitCase($this);
});

it("opens one recovery under a raced double reversal on {$label}", function (): void {
    lcrDoubleReverseCase($this);
});

it("releases exactly once under a raced double cancel on {$label}", function (): void {
    lcrDoubleCancelCase($this);
});

it("confirms a raced manual payout exactly once on {$label}", function (): void {
    lcrDoubleConfirmCase($this);
});
