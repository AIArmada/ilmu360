<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\CreateRewardProgramAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardFundingEntry;
use AIArmada\Rewards\Support\RewardBudgetLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Feature\Rewards\Host\Support\RewardFinanceWorkerDb;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

// R5 races on the isolated drivers: concurrent allocations from one
// deposit cannot overallocate, concurrent evaluations obligate without
// lost reserves, and an established stale snapshot never blinds the
// funding lock.

function fundingRaceReadyDir(string $case): string
{
    $token = ParallelTesting::token();
    $dir = sys_get_temp_dir().'/rewards-funding-race-'.($token !== false ? $token : 'serial').'-'.$case.'-'.getmypid();

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach (glob($dir.'/ready-*') ?: [] as $stale) {
        unlink($stale);
    }

    foreach (glob($dir.'/phase-*') ?: [] as $stale) {
        unlink($stale);
    }

    return $dir;
}

/**
 * @param  string|array{0: string, 1: string}  $modes
 * @param  array<string, mixed>  $payload
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function runFundingRacePair(string $database, string|array $modes, array $payload, string $case): array
{
    $readyDir = fundingRaceReadyDir($case);
    file_put_contents($readyDir.'/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));

    $worker = __DIR__.'/RewardFundingRaceWorker.php';

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
 * @return array{program_id: string, currency: string, amount: int, ref: string, staff_id: string, decided_at: string}
 */
function fundingRaceSlotPayload(string $programId, string $ref, int $amount, string $staff): array
{
    return [
        'program_id' => $programId,
        'currency' => 'MYR',
        'amount' => $amount,
        'ref' => $ref,
        'staff_id' => $staff,
        'decided_at' => '2026-06-03T00:00:00Z',
    ];
}

it('refuses concurrent overallocation of one deposit on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('allocap');

    try {
        $staff = (string) Str::uuid();

        RecordRewardFundingSourceAction::run(
            'TREASURY-race-cap', 'MYR', 10000, 'user', $staff, 'staff',
            null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
        );

        $programA = CreateRewardProgramAction::run('race-cap-a-'.Str::lower(Str::random(6)), 'Race cap A', RewardProgramMode::Cash, null, null, 'race');
        $programB = CreateRewardProgramAction::run('race-cap-b-'.Str::lower(Str::random(6)), 'Race cap B', RewardProgramMode::Cash, null, null, 'race');

        // Two 6000 allocations race against one 10000 deposit: exactly
        // one wins, the loser is refused, the total never exceeds.
        [$first, $second] = runFundingRacePair($database, ['allocate', 'allocate'], [
            'slot-0' => fundingRaceSlotPayload((string) $programA->getKey(), 'TREASURY-race-cap', 6000, $staff),
            'slot-1' => fundingRaceSlotPayload((string) $programB->getKey(), 'TREASURY-race-cap', 6000, $staff),
        ], 'allocap');

        Assert::assertSame(0, $first['exit'] ?? null, 'Worker 0 failed: '.($first['error'] ?? json_encode($first)));
        Assert::assertSame(0, $second['exit'] ?? null, 'Worker 1 failed: '.($second['error'] ?? json_encode($second)));

        $wins = array_filter([$first['ok'] ?? false, $second['ok'] ?? false]);

        Assert::assertCount(1, $wins, 'Exactly one allocation wins: '.json_encode([$first, $second]));

        $loser = ($first['ok'] ?? false) ? $second : $first;

        Assert::assertStringContainsString('refusing overallocation', (string) ($loser['error'] ?? ''));

        $total = (int) RewardFundingEntry::query()->sum('amount_minor');

        Assert::assertSame(6000, $total);
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

it('obligates concurrent evaluations without lost reserves on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('obligate');

    try {
        [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('race-obl', RewardProgramMode::Cash);
        $staff = (string) Str::uuid();

        RecordRewardFundingSourceAction::run(
            'TREASURY-race-obl', 'MYR', 10000, 'user', $staff, 'staff',
            null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
        );
        RecordRewardFundingAction::run(
            $program, 'MYR', 10000, 'TREASURY-race-obl', 'user', $staff, 'staff',
            null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
        );

        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
        $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

        $memberOne = (string) Str::uuid();
        $memberTwo = (string) Str::uuid();

        EnrollRewardMemberAction::run($program, 'user', $memberOne, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));
        EnrollRewardMemberAction::run($program, 'user', $memberTwo, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

        [$first, $second] = runFundingRacePair($database, ['evaluate', 'evaluate'], [
            'slot-0' => [
                'program_id' => (string) $program->getKey(),
                'member_id' => $memberOne,
                'source_key' => 'src-race-obl-1',
                'decided_at' => '2026-06-15T00:00:00Z',
            ],
            'slot-1' => [
                'program_id' => (string) $program->getKey(),
                'member_id' => $memberTwo,
                'source_key' => 'src-race-obl-2',
                'decided_at' => '2026-06-15T00:00:00Z',
            ],
        ], 'obligate');

        Assert::assertSame(0, $first['exit'] ?? null, 'Worker 0 failed: '.($first['error'] ?? json_encode($first)));
        Assert::assertSame(0, $second['exit'] ?? null, 'Worker 1 failed: '.($second['error'] ?? json_encode($second)));
        Assert::assertTrue((bool) ($first['ok'] ?? false), 'Worker 0 refused: '.($first['error'] ?? ''));
        Assert::assertTrue((bool) ($second['ok'] ?? false), 'Worker 1 refused: '.($second['error'] ?? ''));
        Assert::assertSame('held', (string) ($first['outcome'] ?? ''));
        Assert::assertSame('held', (string) ($second['outcome'] ?? ''));

        // Both obligations landed; neither evaluation lost the other's
        // reserve under the shared funding lock.
        Assert::assertSame(
            ['funded' => 10000, 'reserved' => 1000, 'net_spent' => 0, 'available' => 9000],
            RewardBudgetLedger::balances((string) $program->getKey(), 'MYR'),
        );
    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});

it('keeps the funding lock current under an established stale snapshot on real drivers', function () {
    [$database, $previous] = RewardFinanceWorkerDb::setUp('staleread');

    try {
        $staff = (string) Str::uuid();

        RecordRewardFundingSourceAction::run(
            'TREASURY-race-stale', 'MYR', 10000, 'user', $staff, 'staff',
            null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
        );
        RecordRewardFundingSourceAction::run(
            'TREASURY-race-stale-b', 'MYR', 6000, 'user', $staff, 'staff',
            null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
        );

        $program = CreateRewardProgramAction::run('race-stale-'.Str::lower(Str::random(6)), 'Race stale', RewardProgramMode::Cash, null, null, 'race');

        RecordRewardFundingAction::run(
            $program, 'MYR', 4000, 'TREASURY-race-stale', 'user', $staff, 'staff',
            null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
        );

        [$observer, $allocator] = runFundingRacePair($database, ['stale-observe', 'stale-allocate'], [
            'stale-observe' => [
                'program_id' => (string) $program->getKey(),
                'currency' => 'MYR',
            ],
            'stale-allocate' => fundingRaceSlotPayload((string) $program->getKey(), 'TREASURY-race-stale-b', 3000, $staff),
        ], 'staleread');

        Assert::assertSame(0, $observer['exit'] ?? null, 'Observer failed: '.($observer['error'] ?? json_encode($observer)));
        Assert::assertSame(0, $allocator['exit'] ?? null, 'Allocator failed: '.($allocator['error'] ?? json_encode($allocator)));
        Assert::assertTrue((bool) ($allocator['ok'] ?? false), 'Allocation refused: '.($allocator['error'] ?? ''));
        Assert::assertTrue((bool) ($observer['ok'] ?? false), 'Observer refused: '.($observer['error'] ?? ''));

        // The observer read 4000 inside its open transaction before the
        // allocator committed 3000 more. The locking read still observes
        // the current 7000 total on both drivers.
        Assert::assertSame(4000, (int) ($observer['stale_funded'] ?? -1));
        Assert::assertSame(7000, (int) ($observer['fresh_funded'] ?? -1));

    } finally {
        RewardFinanceWorkerDb::tearDown($database, $previous);
    }
});
