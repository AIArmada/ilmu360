<?php

declare(strict_types=1);

// Permanent race worker for RewardFundingRaceTest. Executed only as a standalone
// OS process (`php RewardFundingRaceWorker.php --driver=... ...`); returns
// immediately when merely included, so test runners never execute it.

use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\RecordRewardFundingAction;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardBudgetLedger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$options = getopt('', [
    'driver:',
    'host:',
    'port:',
    'database:',
    'username:',
    'password:',
    'mode:',
    'ready-dir:',
    'slot:',
]);

$fail = static function (string $message): never {
    echo json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR)."\n";

    exit(1);
};

$driver = (string) ($options['driver'] ?? '');
$host = (string) ($options['host'] ?? '');
$port = (int) ($options['port'] ?? 0);
$database = (string) ($options['database'] ?? '');
$mode = (string) ($options['mode'] ?? '');
$readyDir = (string) ($options['ready-dir'] ?? '');
$slot = (int) ($options['slot'] ?? -1);

if ($host !== '127.0.0.1') {
    $fail('Refusing a non-loopback host.');
}

if (($driver === 'pgsql' && $port !== 45432) || ($driver === 'mariadb' && $port !== 43306)) {
    $fail('Refusing a non-isolated database port.');
}

if (preg_match('/^share_rewards_test_rewards_finance_[a-z0-9_]{1,27}$/', $database) !== 1) {
    $fail('Refusing a database outside the isolated rewards-finance namespace.');
}

if (! in_array($mode, ['allocate', 'evaluate', 'stale-observe', 'stale-allocate'], true) || $readyDir === '' || ! in_array($slot, [0, 1], true)) {
    $fail('Missing race job arguments.');
}

$payloadFile = $readyDir.'/payload.json';

if (! is_file($payloadFile)) {
    $fail('Missing race payload.');
}

$payload = json_decode((string) file_get_contents($payloadFile), true);

if (! is_array($payload)) {
    $fail('Race payload must be JSON.');
}

$basePath = realpath(__DIR__.'/../../../../');

if (! is_string($basePath) || ! is_file($basePath.'/bootstrap/app.php')) {
    $fail('Unable to locate the application base path.');
}

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$workerConfig = $driver === 'pgsql'
    ? [
        'driver' => 'pgsql',
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => (string) ($options['username'] ?? ''),
        'password' => (string) ($options['password'] ?? ''),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]
    : [
        'driver' => 'mariadb',
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => (string) ($options['username'] ?? ''),
        'password' => (string) ($options['password'] ?? ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'timezone' => '+00:00',
    ];

config(['database.connections.rewards_finance_race' => $workerConfig]);
DB::purge('rewards_finance_race');
DB::setDefaultConnection('rewards_finance_race');

// File barrier: both slots start the critical section together.
file_put_contents($readyDir.'/ready-'.$slot, (string) $slot);

$deadline = microtime(true) + 25.0;

while ((! is_file($readyDir.'/ready-0') || ! is_file($readyDir.'/ready-1')) && microtime(true) < $deadline) {
    usleep(20000);
}

if (! is_file($readyDir.'/ready-0') || ! is_file($readyDir.'/ready-1')) {
    $fail('Race barrier timed out.');
}

usleep(50000 * $slot);

try {
    $slotKey = 'slot-'.$slot;
    $p = isset($payload[$slotKey]) && is_array($payload[$slotKey])
        ? $payload[$slotKey]
        : (isset($payload[$mode]) && is_array($payload[$mode]) ? $payload[$mode] : $payload);

    $result = match ($mode) {
        'allocate' => (function () use ($p): array {
            $entry = RecordRewardFundingAction::run(
                (string) $p['program_id'], (string) $p['currency'], (int) $p['amount'],
                (string) $p['ref'], 'user', (string) $p['staff_id'], 'staff', null,
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'entry_id' => (string) $entry->getKey()];
        })(),
        'evaluate' => (function () use ($p): array {
            $program = RewardProgram::query()->whereKey((string) $p['program_id'])->firstOrFail();

            $reader = new FakeRewardSourceFactReader;
            $reader->seed(RewardLedgerFixtures::fact((string) $p['source_key'], (string) $p['member_id']));

            $result = (new EvaluateRewardEntitlementAction($reader))->handle(
                $program, RewardComponent::Cash, 'user', (string) $p['member_id'], RewardBeneficiaryRole::Recipient,
                (string) $p['source_key'], CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'award_id' => (string) $result->award->getKey(), 'outcome' => $result->outcome->value];
        })(),
        // Holds an open transaction with an established snapshot read,
        // then proves the funding lock still observes current rows.
        'stale-observe' => (function () use ($p, $readyDir, $fail): array {
            DB::beginTransaction();

            try {
                $stale = RewardBudgetLedger::balances((string) $p['program_id'], (string) $p['currency']);
                file_put_contents($readyDir.'/phase-a-read', 'read');

                $phase = microtime(true) + 25.0;

                while (! is_file($readyDir.'/phase-b-done') && microtime(true) < $phase) {
                    usleep(20000);
                }

                if (! is_file($readyDir.'/phase-b-done')) {
                    $fail('Stale-observe phase timed out.');
                }

                // Plain re-read (snapshot-bound on repeatable-read) next
                // to the locking read (current on every isolation).
                $reread = RewardBudgetLedger::balances((string) $p['program_id'], (string) $p['currency']);
                $fresh = RewardBudgetLedger::lockedBalances(
                    (string) $p['program_id'], (string) $p['currency'],
                    RewardBudgetLedger::lockFunding((string) $p['program_id'], (string) $p['currency']),
                );

                DB::commit();

                return [
                    'ok' => true,
                    'stale_funded' => $stale['funded'],
                    'reread_funded' => $reread['funded'],
                    'fresh_funded' => $fresh['funded'],
                ];
            } catch (Throwable $throwable) {
                DB::rollBack();

                throw $throwable;
            }
        })(),
        'stale-allocate' => (function () use ($p, $readyDir, $fail): array {
            $phase = microtime(true) + 25.0;

            while (! is_file($readyDir.'/phase-a-read') && microtime(true) < $phase) {
                usleep(20000);
            }

            if (! is_file($readyDir.'/phase-a-read')) {
                $fail('Stale-allocate phase timed out.');
            }

            $entry = RecordRewardFundingAction::run(
                (string) $p['program_id'], (string) $p['currency'], (int) $p['amount'],
                (string) $p['ref'], 'user', (string) $p['staff_id'], 'staff', null,
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            file_put_contents($readyDir.'/phase-b-done', 'done');

            return ['ok' => true, 'entry_id' => (string) $entry->getKey()];
        })(),
    };

    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";

    exit(0);
} catch (Throwable $throwable) {
    // Business refusals are race outcomes, not infrastructure failures:
    // report them with a zero exit so the test can assert exactly-one-wins.
    echo json_encode([
        'ok' => false,
        'error' => substr($throwable->getMessage(), 0, 300),
    ], JSON_THROW_ON_ERROR)."\n";

    exit(0);
}
