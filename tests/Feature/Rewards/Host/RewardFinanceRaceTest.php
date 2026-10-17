<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\ClawBackAwardPointsAction;
use AIArmada\Rewards\Actions\CreateRewardBenefitItemAction;
use AIArmada\Rewards\Actions\CreditRewardPointsAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\FulfillRewardBenefitAction;
use AIArmada\Rewards\Actions\RedeemRewardBenefitAction;
use AIArmada\Rewards\Actions\ReserveRewardPointsAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardBenefitGrantStatus;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBenefitGrant;
use AIArmada\Rewards\Models\RewardBenefitItem;
use AIArmada\Rewards\Models\RewardPointLot;
use AIArmada\Rewards\Models\RewardPointMovement;
use AIArmada\Rewards\Models\RewardPointReservationSubmission;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Support\RewardBenefitStockLedger;
use AIArmada\Rewards\Support\RewardPointLedger;
use AIArmada\Vouchers\Models\Voucher as VoucherModel;
use AIArmada\Vouchers\Models\VoucherUsage;
use AIArmada\Vouchers\States\Expired;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Feature\Rewards\Host\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Host\Support\RewardFinanceWorkerDb;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;
use Tests\Support\RealDriverFixture;

function financeRaceReadyDir(string $case): string
{
    $token = ParallelTesting::token();
    $dir = sys_get_temp_dir().'/rewards-finance-race-'.($token !== false ? $token : 'serial').'-'.$case.'-'.getmypid();

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach (glob($dir.'/ready-*') ?: [] as $stale) {
        unlink($stale);
    }

    return $dir;
}

/**
 * @param  string|array{0: string, 1: string}  $modes
 * @param  array<string, mixed>  $payload
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function runFinanceRacePair(string $database, string|array $modes, array $payload, string $case): array
{
    $readyDir = financeRaceReadyDir($case);
    file_put_contents($readyDir.'/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));


    $worker = __DIR__.'/RewardFinanceRaceWorker.php';

    $make = fn (int $slot): Process => new Process([
        PHP_BINARY, $worker,
        '--database='.$database,
        '--mode='.(is_array($modes) ? $modes[$slot] : $modes),
        '--ready-dir='.$readyDir,
        '--slot='.$slot,
    ], timeout: 120);

    $first = $make(0);
    $second = $make(1);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();

    $decode = static function (Process $process): array {
        $exit = $process->getExitCode();
        $output = trim($process->getOutput());

        if ($output === '') {
            return ['ok' => false, 'exit' => $exit, 'error' => substr(trim($process->getErrorOutput()), 0, 300)];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return ['ok' => false, 'exit' => $exit, 'error' => 'Worker output was not JSON.'];
        }

        $decoded['exit'] = $exit;

        return $decoded;
    };

    return [$decode($first), $decode($second)];
}

/**
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 */
function financeRaceWorkersExited(array $a, array $b): void
{
    Assert::assertSame(0, $a['exit'] ?? null, 'Worker A failed: '.($a['error'] ?? json_encode($a)));
    Assert::assertSame(0, $b['exit'] ?? null, 'Worker B failed: '.($b['error'] ?? json_encode($b)));
}

/**
 * Seeds an active points program, an enrolled member, and a fulfilled
 * 10-point award on the worker connection.
 *
 * @return array{0: mixed, 1: string, 2: RewardAward}
 */
function financeRaceFulfilledAward(string $prefix, string $source): array
{
    [$program, , , $memberId] = RewardLedgerFixtures::seedActiveEnrolled($prefix);

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $staff = RewardLedgerFixtures::staffId();

    $fulfilled = (new TransitionRewardAwardAction(null, $reader))->handle(
        $result->award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill',
        null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    return [$program, $memberId, $fulfilled->refresh()];
}

it('closes mark-vs-reversal to exactly one winner on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('markrev');

    try {
        [$program, $memberId, $award] = financeRaceFulfilledAward('race-markrev', 'src-race-markrev-1');
        $staff = RewardLedgerFixtures::staffId();

        ReserveRewardPointsAction::run(
            'user', $memberId, $program->family_key, 'pts', 10, 'rsv:race-markrev-1', 'hold',
            CarbonImmutable::parse('2026-06-16T12:00:00Z'),
        );

        [$clawback, $mark] = runFinanceRacePair($database, ['clawback', 'mark'], [
            'clawback' => [
                'award_id' => (string) $award->getKey(),
                'staff_id' => $staff,
                'decided_at' => '2026-06-17T00:00:00Z',
            ],
            'mark' => [
                'reservation_key' => 'rsv:race-markrev-1',
                'reference' => 'race-handoff-1',
                'decided_at' => '2026-06-17T00:00:00Z',
            ],
        ], 'markrev');

        financeRaceWorkersExited($clawback, $mark);

        $winners = (int) ($clawback['ok'] ?? false) + (int) ($mark['ok'] ?? false);

        Assert::assertSame(1, $winners, 'Exactly one of reversal/mark must win: '.json_encode([$clawback, $mark]));

        if (($clawback['ok'] ?? false) === true) {
            expect($mark['error'] ?? '')->toContain('no pending pieces')
                ->and(RewardPointReservationSubmission::query()->count())->toBe(0)
                ->and(RewardPointMovement::query()->where('reason', 'like', 'reversal-void:%')->count())->toBe(1)
                ->and(RewardPointLot::query()->where('award_id', $award->getKey())->firstOrFail()->status->value)->toBe('depleted');
        } else {
            expect($clawback['error'] ?? '')->toContain('unknown external status')
                ->and(RewardPointReservationSubmission::query()->where('reservation_key', 'rsv:race-markrev-1')->firstOrFail()->status)->toBe('sending')
                ->and(RewardPointMovement::query()->where('reason', 'like', 'reversal-void:%')->count())->toBe(0);
        }

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($buckets->available + $buckets->reserved)->toBeLessThanOrEqual(10);
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

it('closes redeem-vs-reversal to exactly one winner on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('redrev');

    try {
        [$program, $memberId, $award] = financeRaceFulfilledAward('race-redrev', 'src-race-redrev-1');
        $staff = RewardLedgerFixtures::staffId();

        $item = CreateRewardBenefitItemAction::run(
            $program, 'race-seat', 'Race seat', ['Good standing'], 10, 'pts', false, 5,
            'user', $staff, 'staff',
        );

        [$clawback, $redeem] = runFinanceRacePair($database, ['clawback', 'redeem'], [
            'clawback' => [
                'award_id' => (string) $award->getKey(),
                'staff_id' => $staff,
                'decided_at' => '2026-06-17T00:00:00Z',
            ],
            'redeem' => [
                'recipient_type' => 'user',
                'recipient_id' => $memberId,
                'family_key' => $program->family_key,
                'item_id' => (string) $item->getKey(),
                'quantity' => 1,
                'redemption_key' => 'race-redrev-1',
                'decided_at' => '2026-06-17T00:00:00Z',
            ],
        ], 'redrev');

        financeRaceWorkersExited($clawback, $redeem);

        $winners = (int) ($clawback['ok'] ?? false) + (int) ($redeem['ok'] ?? false);

        Assert::assertSame(1, $winners, 'Exactly one of reversal/redeem must win: '.json_encode([$clawback, $redeem]));

        if (($clawback['ok'] ?? false) === true) {
            expect($redeem['error'] ?? '')->toContain('Insufficient available')
                ->and(RewardBenefitGrant::query()->count())->toBe(0)
                ->and(RewardPointReservationSubmission::query()->count())->toBe(0);
        } else {
            expect($clawback['error'] ?? '')->toContain('unknown external status')
                ->and(RewardBenefitGrant::query()->where('grant_key', 'redeem:race-redrev-1')->firstOrFail()->status->value)->toBe('reserved')
                ->and(RewardPointReservationSubmission::query()->where('reservation_key', 'rsv:redeem:race-redrev-1')->firstOrFail()->status)->toBe('sending');
        }
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

function financeRaceUserRow(string $memberId): void
{
    DB::table('users')->insert([
        'id' => $memberId,
        'name' => 'Race member',
        'created_at' => CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        'updated_at' => CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    ]);
}

function financeRaceCredit(string $memberId, string $family, string $programId, int $points, string $key): void
{
    CreditRewardPointsAction::run(
        'user', $memberId, $family, 'pts', $points,
        CarbonImmutable::parse('2026-06-11T00:00:00Z'), null,
        null, $programId, $key,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );
}

function financeRaceReservedGrant(RewardProgram $program, string $memberId, RewardBenefitItem $item, string $key, string $adapter = 'manual'): RewardBenefitGrant
{
    return RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 1, $key, $adapter,
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-16T12:00:00Z'),
    );
}

it('closes fulfill-vs-cancel to exactly one winner on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('fulcan');

    try {
        [$program, $memberId] = financeRaceFulfilledAward('race-fulcan', 'src-race-fulcan-1');
        $staff = RewardLedgerFixtures::staffId();

        $item = CreateRewardBenefitItemAction::run(
            $program, 'fulcan-seat', 'Fulcan seat', ['Good standing'], 10, 'pts', false, 5,
            'user', $staff, 'staff',
        );

        $grant = financeRaceReservedGrant($program, $memberId, $item, 'race-fulcan-1');

        [$fulfill, $cancel] = runFinanceRacePair($database, ['fulfill', 'cancel'], [
            'fulfill' => [
                'grant_id' => (string) $grant->getKey(),
                'handoff_reference' => 'RACE-DESK-1',
                'staff_id' => $staff,
                'decided_at' => '2026-06-17T00:00:00Z',
            ],
            'cancel' => [
                'grant_id' => (string) $grant->getKey(),
                'staff_id' => $staff,
                'reason' => 'race-cancel',
                'decided_at' => '2026-06-17T00:00:00Z',
            ],
        ], 'fulcan');

        financeRaceWorkersExited($fulfill, $cancel);

        $winners = (int) ($fulfill['ok'] ?? false) + (int) ($cancel['ok'] ?? false);

        Assert::assertSame(1, $winners, 'Exactly one of fulfill/cancel must win: '.json_encode([$fulfill, $cancel]));

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        if (($fulfill['ok'] ?? false) === true) {
            expect($cancel['error'] ?? '')->toContain('Only reserved grants')
                ->and($grant->refresh()->status)->toBe(RewardBenefitGrantStatus::Issued)
                ->and($buckets->spent)->toBe(10)
                ->and($buckets->reserved)->toBe(0)
                ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(4)
                ->and(RewardPointReservationSubmission::query()->where('reservation_key', 'rsv:redeem:race-fulcan-1')->firstOrFail()->status)->toBe('delivered');
        } else {
            expect($fulfill['error'] ?? '')->toContain('Only reserved grants')
                ->and($grant->refresh()->status)->toBe(RewardBenefitGrantStatus::Revoked)
                ->and($buckets->available)->toBe(10)
                ->and($buckets->reserved)->toBe(0)
                ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(5)
                ->and(RewardPointReservationSubmission::query()->where('reservation_key', 'rsv:redeem:race-fulcan-1')->firstOrFail()->status)->toBe('failed');
        }
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

it('restores exactly once under a raced double cancel on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('dblcan');

    try {
        [$program, $memberId] = financeRaceFulfilledAward('race-dblcan', 'src-race-dblcan-1');
        $staff = RewardLedgerFixtures::staffId();

        $item = CreateRewardBenefitItemAction::run(
            $program, 'dblcan-seat', 'Dblcan seat', ['Good standing'], 10, 'pts', false, 5,
            'user', $staff, 'staff',
        );

        $grant = financeRaceReservedGrant($program, $memberId, $item, 'race-dblcan-1');

        $payload = [
            'grant_id' => (string) $grant->getKey(),
            'staff_id' => $staff,
            'reason' => 'race-cancel',
            'decided_at' => '2026-06-17T00:00:00Z',
        ];

        [$first, $second] = runFinanceRacePair($database, 'cancel', $payload, 'dblcan');

        financeRaceWorkersExited($first, $second);

        $winners = (int) ($first['ok'] ?? false) + (int) ($second['ok'] ?? false);

        Assert::assertSame(1, $winners, 'Exactly one cancel must win: '.json_encode([$first, $second]));

        $loser = ($first['ok'] ?? false) === true ? $second : $first;

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($loser['error'] ?? '')->toContain('Only reserved grants')
            ->and($grant->refresh()->status)->toBe(RewardBenefitGrantStatus::Revoked)
            ->and($buckets->available)->toBe(10)
            ->and($buckets->reserved)->toBe(0)
            ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(5)
            ->and(RewardPointReservationSubmission::query()->where('reservation_key', 'rsv:redeem:race-dblcan-1')->firstOrFail()->status)->toBe('failed');
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

it('closes coupon-use-vs-revocation to exactly one winner on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('userev');

    try {
        [$program, $memberId] = financeRaceFulfilledAward('race-userev', 'src-race-userev-1');
        $staff = RewardLedgerFixtures::staffId();

        financeRaceUserRow($memberId);

        $item = CreateRewardBenefitItemAction::run(
            $program, 'userev-credit', 'Userev credit', ['Good standing'], 10, 'pts', false, 3,
            'user', $staff, 'staff', null, ['voucher' => ['type' => 'fixed', 'value' => 2000, 'currency' => 'MYR']],
        );

        $grant = financeRaceReservedGrant($program, $memberId, $item, 'race-userev-1', 'coupon');

        $issued = FulfillRewardBenefitAction::run(
            $grant, [], 'user', $staff, 'staff', CarbonImmutable::parse('2026-06-16T13:00:00Z'),
        );

        $code = (string) $issued->external_ref;

        [$use, $revoke] = runFinanceRacePair($database, ['record_usage', 'revoke'], [
            'record_usage' => [
                'code' => $code,
                'amount' => 2000,
                'currency' => 'MYR',
                'idempotency_key' => 'race-userev-use-1',
                'redeemed_by_id' => $memberId,
            ],
            'revoke' => [
                'grant_id' => (string) $issued->getKey(),
                'staff_id' => $staff,
                'reason' => 'race-revoke',
                'decided_at' => '2026-06-17T00:00:00Z',
            ],
        ], 'userev');

        financeRaceWorkersExited($use, $revoke);

        $winners = (int) ($use['ok'] ?? false) + (int) ($revoke['ok'] ?? false);

        Assert::assertSame(1, $winners, 'Exactly one of use/revoke must win: '.json_encode([$use, $revoke]));

        $voucher = VoucherModel::query()->where('code', $code)->firstOrFail();
        $usages = VoucherUsage::query()->where('voucher_id', $voucher->getKey())->count();

        if (($use['ok'] ?? false) === true) {
            expect($revoke['error'] ?? '')->toContain('was used')
                ->and($issued->refresh()->status)->toBe(RewardBenefitGrantStatus::Issued)
                ->and($usages)->toBe(1);
        } else {
            $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

            expect($use['error'] ?? '')->toContain('has expired')
                ->and($issued->refresh()->status)->toBe(RewardBenefitGrantStatus::Revoked)
                ->and($usages)->toBe(0)
                ->and($voucher->refresh()->status instanceof Expired)->toBeTrue()
                ->and($buckets->available)->toBe(10)
                ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(3);
        }
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

it('converges fulfill-vs-reversal without deadlock on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('fulrev');

    try {
        [$program, $memberId, $award] = financeRaceFulfilledAward('race-fulrev', 'src-race-fulrev-1');
        $staff = RewardLedgerFixtures::staffId();

        $item = CreateRewardBenefitItemAction::run(
            $program, 'fulrev-seat', 'Fulrev seat', ['Good standing'], 10, 'pts', false, 5,
            'user', $staff, 'staff',
        );

        $grant = financeRaceReservedGrant($program, $memberId, $item, 'race-fulrev-1');

        [$fulfill, $clawback] = runFinanceRacePair($database, ['fulfill', 'clawback'], [
            'fulfill' => [
                'grant_id' => (string) $grant->getKey(),
                'handoff_reference' => 'RACE-DESK-2',
                'staff_id' => $staff,
                'decided_at' => '2026-06-17T00:00:00Z',
            ],
            'clawback' => [
                'award_id' => (string) $award->getKey(),
                'staff_id' => $staff,
                'decided_at' => '2026-06-17T00:00:00Z',
            ],
        ], 'fulrev');

        financeRaceWorkersExited($fulfill, $clawback);

        // Fulfill always captures; reversal either refuses the sending
        // handoff (then proceeds coherently right after) or lands after
        // the capture and opens the consumed-value dispute directly. Both
        // take lots-before-movements, so no interleaving deadlocks.
        expect($fulfill['ok'] ?? false)->toBeTrue()
            ->and($grant->refresh()->status)->toBe(RewardBenefitGrantStatus::Issued);

        if (($clawback['ok'] ?? false) === true) {
            expect($clawback['clawed_points'] ?? null)->toBe(0)
                ->and($clawback['consumed_points'] ?? null)->toBe(10)
                ->and($clawback['dispute_id'] ?? null)->not->toBeNull();
        } else {
            expect($clawback['error'] ?? '')->toContain('unknown external status');

            // Post-fulfillment reversal then proceeds coherently: nothing
            // left to claw, the consumed value opens a dispute.
            $settlement = ClawBackAwardPointsAction::run(
                (string) $award->getKey(), 'user', $staff, 'staff', 'race-reversal',
                CarbonImmutable::parse('2026-06-18T00:00:00Z'),
            );

            expect($settlement['clawed_points'])->toBe(0)
                ->and($settlement['consumed_points'])->toBe(10)
                ->and($settlement['dispute_id'])->not->toBeNull();
        }

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-19T00:00:00Z'));

        expect($buckets->spent)->toBe(10)
            ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(4);
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

it('refuses concurrent over-reservation beyond capacity on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('stockcap');

    try {
        [$program, $memberA] = financeRaceFulfilledAward('race-stockcap', 'src-race-stockcap-a');
        $staff = RewardLedgerFixtures::staffId();

        $memberB = (string) Str::uuid();
        financeRaceCredit($memberB, $program->family_key, (string) $program->getKey(), 10, 'seed:race-stockcap-b');

        $item = CreateRewardBenefitItemAction::run(
            $program, 'stockcap-seat', 'Stockcap seat', ['Good standing'], 10, 'pts', false, 1,
            'user', $staff, 'staff',
        );

        $slotPayload = function (string $member, string $key) use ($program, $item): array {
            return [
                'recipient_type' => 'user',
                'recipient_id' => $member,
                'family_key' => $program->family_key,
                'item_id' => (string) $item->getKey(),
                'quantity' => 1,
                'redemption_key' => $key,
                'decided_at' => '2026-06-17T00:00:00Z',
            ];
        };

        [$first, $second] = runFinanceRacePair($database, ['redeem', 'redeem'], [
            'slots' => [$slotPayload($memberA, 'race-stockcap-a'), $slotPayload($memberB, 'race-stockcap-b')],
        ], 'stockcap');

        financeRaceWorkersExited($first, $second);

        $winners = (int) ($first['ok'] ?? false) + (int) ($second['ok'] ?? false);

        Assert::assertSame(1, $winners, 'Exactly one redemption must win the last unit: '.json_encode([$first, $second]));

        $loser = ($first['ok'] ?? false) === true ? $second : $first;
        $winnerId = ($first['ok'] ?? false) === true ? $memberA : $memberB;
        $loserId = $winnerId === $memberA ? $memberB : $memberA;

        $winnerBuckets = RewardPointLedger::balancesFor('user', $winnerId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));
        $loserBuckets = RewardPointLedger::balancesFor('user', $loserId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($loser['error'] ?? '')->toContain('Insufficient benefit stock')
            ->and(RewardBenefitGrant::query()->count())->toBe(1)
            ->and(RewardBenefitGrant::query()->firstOrFail()->recipient_id)->toBe($winnerId)
            ->and($winnerBuckets->reserved)->toBe(10)
            ->and($loserBuckets->available)->toBe(10)
            ->and($loserBuckets->reserved)->toBe(0)
            ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(0);
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

it('collapses a raced same-key same-term double redeem onto one grant', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('dblkey');

    try {
        [$program, $memberId] = financeRaceFulfilledAward('race-dblkey', 'src-race-dblkey-1');
        $staff = RewardLedgerFixtures::staffId();

        $item = CreateRewardBenefitItemAction::run(
            $program, 'dblkey-seat', 'Dblkey seat', ['Good standing'], 10, 'pts', false, 5,
            'user', $staff, 'staff',
        );

        [$first, $second] = runFinanceRacePair($database, 'redeem', [
            'recipient_type' => 'user',
            'recipient_id' => $memberId,
            'family_key' => $program->family_key,
            'item_id' => (string) $item->getKey(),
            'quantity' => 1,
            'redemption_key' => 'race-dblkey-1',
            'decided_at' => '2026-06-17T00:00:00Z',
        ], 'dblkey');

        financeRaceWorkersExited($first, $second);

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($first['ok'] ?? false)->toBeTrue()
            ->and($second['ok'] ?? false)->toBeTrue()
            ->and($first['grant_id'] ?? null)->toBe($second['grant_id'] ?? null)
            ->and(RewardBenefitGrant::query()->where('grant_key', 'redeem:race-dblkey-1')->count())->toBe(1)
            ->and($buckets->reserved)->toBe(10)
            ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(4);
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

it('refuses a raced same-key cross-recipient redeem on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('xkey');

    try {
        [$program, $memberA] = financeRaceFulfilledAward('race-xkey', 'src-race-xkey-a');
        $staff = RewardLedgerFixtures::staffId();

        $memberB = (string) Str::uuid();
        financeRaceCredit($memberB, $program->family_key, (string) $program->getKey(), 10, 'seed:race-xkey-b');

        $item = CreateRewardBenefitItemAction::run(
            $program, 'xkey-seat', 'Xkey seat', ['Good standing'], 10, 'pts', false, 5,
            'user', $staff, 'staff',
        );

        $slotPayload = function (string $member) use ($program, $item): array {
            return [
                'recipient_type' => 'user',
                'recipient_id' => $member,
                'family_key' => $program->family_key,
                'item_id' => (string) $item->getKey(),
                'quantity' => 1,
                'redemption_key' => 'race-xkey-1',
                'decided_at' => '2026-06-17T00:00:00Z',
            ];
        };

        [$first, $second] = runFinanceRacePair($database, ['redeem', 'redeem'], [
            'slots' => [$slotPayload($memberA), $slotPayload($memberB)],
        ], 'xkey');

        financeRaceWorkersExited($first, $second);

        $winners = (int) ($first['ok'] ?? false) + (int) ($second['ok'] ?? false);

        Assert::assertSame(1, $winners, 'Exactly one cross-recipient contender must win: '.json_encode([$first, $second]));

        $winnerId = ($first['ok'] ?? false) === true ? $memberA : $memberB;
        $loserId = $winnerId === $memberA ? $memberB : $memberA;
        $loser = ($first['ok'] ?? false) === true ? $second : $first;

        $bucketsB = RewardPointLedger::balancesFor('user', $memberB, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($loser['error'] ?? '')->toContain('different')
            ->and(RewardBenefitGrant::query()->where('grant_key', 'redeem:race-xkey-1')->count())->toBe(1)
            ->and(RewardBenefitGrant::query()->where('grant_key', 'redeem:race-xkey-1')->firstOrFail()->recipient_id)->toBe($winnerId)
            ->and(RewardBenefitGrant::query()->where('recipient_id', $loserId)->count())->toBe(0)
            ->and($bucketsB->available + $bucketsB->reserved)->toBe(10);
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});
