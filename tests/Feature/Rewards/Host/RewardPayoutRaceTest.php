<?php

declare(strict_types=1);

// R4 real-driver proof: independent payout workers race the same reward
// payout request on the isolated PostgreSQL 17 and MariaDB 11.4
// fixtures with controlled provider HTTP. The affiliate send mutex,
// pre-send re-verification, and stable idempotency keys make every
// interleaving coherent: exactly one terminal outcome, exactly-once
// provider sends, and exact budget accounting. Timing is never asserted.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\States\PaidConversion;
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
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardPayoutRequestStatus;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBudgetMovement;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardRecovery;
use AIArmada\Rewards\Support\RewardBudgetLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\ShareTracking\ShareMetricRaceHarness;
use Tests\TestCase;

function lcrProgram(string $family): RewardProgram
{
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
    // reconcile, reverse) run for real against it. Entitlement evaluation
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

function lcrRequest(RewardAward $award, string $memberId, string $method, string $ref): array
{
    config(['affiliates.payouts.stripe.secret_key' => 'sk_test_fixture']);

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

function lcrRace(array $job): array
{
    $job['worker'] = __DIR__.'/RewardPayoutRaceWorker.php';

    return ShareMetricRaceHarness::runRace($job);
}

function lcrSubmitCancelCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker($driver, 'lcrsc');

    try {
        $program = lcrProgram('race-sc-'.$driver);
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-sc-1');
        [$request, $actors] = lcrRequest($award, $memberId, 'stripe_connect', 'acct_race_sc');

        [$first, $second] = lcrRace([
            'driver' => $driver,
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'payout',
            'payload' => [
                'request_id' => (string) $request->getKey(),
                'actor' => ['type' => 'user', 'id' => $actors['staff'], 'role' => 'staff'],
                'reason' => 'race-decision',
                'decided_at' => '2026-06-18T00:00:00Z',
                'fake' => ['posts' => [['status' => 200, 'body' => ['id' => 'tr_race_sc_1']]], 'listed' => []],
                'slots' => [['op' => 'submit'], ['op' => 'cancel']],
            ],
        ]);

        $final = $request->refresh()->status;
        $posts = ($first['posts'] ?? 0) + ($second['posts'] ?? 0);

        // Exactly one terminal outcome, coherent with the ledger: either
        // the send won (confirmed + spent once, cancel refused) or the
        // cancel won (cancelled + released, send aborted). Never both,
        // never a spend beside a release.
        expect($final === RewardPayoutRequestStatus::Confirmed || $final === RewardPayoutRequestStatus::Cancelled)->toBeTrue()
            ->and($posts)->toBeLessThanOrEqual(1);

        if ($final === RewardPayoutRequestStatus::Confirmed) {
            expect(collect([$first, $second])->pluck('outcome')->all())->toContain('aborted')
                ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
                ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 527, 'available' => 9473])
                ->and($posts)->toBe(1);
        } else {
            expect(collect([$first, $second])->pluck('outcome')->all())->toContain('aborted')
                ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
                ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 0, 'available' => 10000])
                ->and($posts)->toBe(0);
        }
    } finally {
        ShareMetricRaceHarness::teardown($driver, $database, $previous);
    }
}

function lcrDoubleSubmitCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker($driver, 'lcrss');

    try {
        $program = lcrProgram('race-ss-'.$driver);
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-ss-1');
        [$request, $actors] = lcrRequest($award, $memberId, 'stripe_connect', 'acct_race_ss');

        [$first, $second] = lcrRace([
            'driver' => $driver,
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'payout',
            'payload' => [
                'request_id' => (string) $request->getKey(),
                'actor' => ['type' => 'user', 'id' => $actors['staff'], 'role' => 'staff'],
                'decided_at' => '2026-06-18T00:00:00Z',
                'fake' => ['posts' => [['status' => 200, 'body' => ['id' => 'tr_race_ss_1']]], 'listed' => []],
                'slots' => [['op' => 'submit'], ['op' => 'submit']],
            ],
        ]);

        // One provider send, one spend, one winner; the loser aborts
        // cleanly instead of double-recording.
        expect($request->refresh()->status)->toBe(RewardPayoutRequestStatus::Confirmed)
            ->and(($first['posts'] ?? 0) + ($second['posts'] ?? 0))->toBe(1)
            ->and(collect([$first, $second])->where('outcome', 'done')->count())->toBe(1)
            ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
            ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 527, 'available' => 9473])
            ->and(RewardBudgetMovement::query()->where('kind', 'spend')->count())->toBe(1);
    } finally {
        ShareMetricRaceHarness::teardown($driver, $database, $previous);
    }
}

function lcrDoubleReverseCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker($driver, 'lcrrr');

    try {
        $program = lcrProgram('race-rr-'.$driver);
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-rr-1');
        [$request, $actors] = lcrRequest($award, $memberId, 'bank_transfer', 'ACCT-RACE-RR');
        $confirmer = (string) Str::uuid();

        $request = SubmitRewardPayoutAction::run(
            $request, 'user', $actors['staff'], 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
        );
        $request = ConfirmRewardPayoutAction::run(
            $request, ['bank_reference' => 'BANK-RACE-RR'], 'user', $confirmer, 'staff',
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
            'driver' => $driver,
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
        ShareMetricRaceHarness::teardown($driver, $database, $previous);
    }
}

function lcrDoubleCancelCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker($driver, 'lcrcc');

    try {
        $program = lcrProgram('race-cc-'.$driver);
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-cc-1');

        config(['affiliates.payouts.stripe.secret_key' => 'sk_test_fixture']);

        $staff = (string) Str::uuid();
        $verifier = (string) Str::uuid();
        $requester = (string) Str::uuid();

        $payee = VerifyRewardPayeeAction::run(
            'user', $memberId, 'stripe_connect', 'acct_race_cc', 'acct_race_cc',
            'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
        );

        // Requested, never approved: no payout exists yet.
        $request = RequestRewardPayoutAction::run(
            $award, $payee, 'stripe_connect', 'program', 'user', $requester, 'staff',
            null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
        );

        [$first, $second] = lcrRace([
            'driver' => $driver,
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
        ShareMetricRaceHarness::teardown($driver, $database, $previous);
    }
}

function lcrLateCompletionCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker($driver, 'lcrlc');

    try {
        $program = lcrProgram('race-lc-'.$driver);
        $memberId = (string) Str::uuid();
        $award = lcrAward($program, $memberId, 'src-race-lc-1');
        [$request, $actors] = lcrRequest($award, $memberId, 'stripe_connect', 'acct_race_lc');

        // Main process drives the request to unknown (provider 500s);
        // both workers then race the late completion.
        Http::fake([
            'api.stripe.com/v1/transfers*' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => []], 200);
                }

                return Http::response(['error' => ['code' => 'api_error']], 500);
            },
        ]);

        $request = SubmitRewardPayoutAction::run(
            $request, 'user', $actors['staff'], 'staff', CarbonImmutable::parse('2026-06-18T00:00:00Z'),
        );

        expect($request->status)->toBe(RewardPayoutRequestStatus::Unknown);

        [$first, $second] = lcrRace([
            'driver' => $driver,
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'payout',
            'payload' => [
                'request_id' => (string) $request->getKey(),
                'actor' => ['type' => 'user', 'id' => $actors['staff'], 'role' => 'staff'],
                'decided_at' => '2026-06-19T00:00:00Z',
                'fake' => ['posts' => [], 'listed' => [['id' => 'tr_race_late_1']]],
                'slots' => [['op' => 'reconcile'], ['op' => 'reconcile']],
            ],
        ]);

        // The late transfer settles both ledgers exactly once; the loser
        // observes the confirmation instead of re-spending.
        expect($request->refresh()->status)->toBe(RewardPayoutRequestStatus::Confirmed)
            ->and(RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'))
            ->toBe(['funded' => 10000, 'reserved' => 0, 'net_spent' => 527, 'available' => 9473])
            ->and(RewardBudgetMovement::query()->where('kind', 'spend')->count())->toBe(1);

        $conversion = AffiliateConversion::query()->whereKey((string) $request->external_reference_id)->firstOrFail();

        expect($conversion->status->equals(PaidConversion::class))->toBeTrue();
    } finally {
        ShareMetricRaceHarness::teardown($driver, $database, $previous);
    }
}

foreach (['pgsql', 'mariadb'] as $lcrDriver) {
    $label = $lcrDriver === 'pgsql' ? 'isolated PostgreSQL' : 'isolated MariaDB';

    it("keeps a raced submit and cancel coherent on {$label}", function () use ($lcrDriver): void {
        lcrSubmitCancelCase($this, $lcrDriver);
    });

    it("sends exactly once under a raced double submit on {$label}", function () use ($lcrDriver): void {
        lcrDoubleSubmitCase($this, $lcrDriver);
    });

    it("opens one recovery under a raced double reversal on {$label}", function () use ($lcrDriver): void {
        lcrDoubleReverseCase($this, $lcrDriver);
    });

    it("releases exactly once under a raced double cancel on {$label}", function () use ($lcrDriver): void {
        lcrDoubleCancelCase($this, $lcrDriver);
    });

    it("settles a raced late completion exactly once on {$label}", function () use ($lcrDriver): void {
        lcrLateCompletionCase($this, $lcrDriver);
    });
}
