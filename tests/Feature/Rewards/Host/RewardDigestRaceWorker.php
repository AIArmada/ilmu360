<?php

declare(strict_types=1);

// Permanent race worker for RewardDigestRaceTest. Executed only as a standalone
// OS process (`php RewardDigestRaceWorker.php --driver=... ...`); returns
// immediately when merely included, so test runners never execute it.

use App\Actions\Rewards\CancelRewardDigestAction;
use App\Actions\Rewards\DispatchDueRewardDigestsAction;
use App\Actions\Rewards\PlanRewardGratitudeDigestAction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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

if (! in_array($mode, ['plan', 'dispatch', 'cancel'], true) || $readyDir === '' || ! in_array($slot, [0, 1], true)) {
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

config(['database.connections.rewards_digest_race' => $workerConfig]);
DB::purge('rewards_digest_race');
DB::setDefaultConnection('rewards_digest_race');

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
    $slotPayload = isset($payload['slots']) && is_array($payload['slots']) ? ($payload['slots'][$slot] ?? null) : null;
    $p = is_array($slotPayload) ? $slotPayload : (isset($payload[$mode]) && is_array($payload[$mode]) ? $payload[$mode] : $payload);

    $result = match ($mode) {
        'plan' => (function () use ($p): array {
            $slotRow = PlanRewardGratitudeDigestAction::run(
                'user',
                (string) $p['member_id'],
                (array) $p['categories'],
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'slot_id' => (string) $slotRow->getKey(), 'categories' => array_values((array) $slotRow->categories)];
        })(),
        'dispatch' => (function () use ($p): array {
            $outcome = DispatchDueRewardDigestsAction::run(50, CarbonImmutable::parse((string) $p['now']));

            return ['ok' => true, 'outcome' => $outcome];
        })(),
        'cancel' => (function () use ($p): array {
            $count = CancelRewardDigestAction::cancelPendingForRecipient(
                'user',
                (string) $p['member_id'],
                (string) $p['reason'],
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'cancelled' => $count];
        })(),
    };

    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";

    exit(0);
} catch (Throwable $exception) {
    $fail(get_class($exception).': '.$exception->getMessage());
}
