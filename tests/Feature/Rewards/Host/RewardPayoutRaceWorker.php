<?php

declare(strict_types=1);

// Permanent race worker for RewardPayoutRaceTest. Executed only as a
// standalone OS process (`php RewardPayoutRaceWorker.php --driver=...
// ...`); returns immediately when merely included, so test runners and
// autoloaders never execute it.

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Rewards\Actions\CancelRewardPayoutAction;
use AIArmada\Rewards\Actions\ReconcileRewardPayoutAction;
use AIArmada\Rewards\Actions\ReverseRewardFulfillmentAction;
use AIArmada\Rewards\Actions\SubmitRewardPayoutAction;
use AIArmada\Rewards\Models\RewardAward;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
    'app-url:',
    'mode:',
    'ready-dir:',
    'slot:',
]);

$fail = static function (string $message, int $exit = 1): never {
    echo json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR)."\n";

    exit($exit);
};

$driver = (string) ($options['driver'] ?? '');
$host = (string) ($options['host'] ?? '');
$port = (int) ($options['port'] ?? 0);
$database = (string) ($options['database'] ?? '');
$mode = (string) ($options['mode'] ?? '');
$readyDir = (string) ($options['ready-dir'] ?? '');
$slot = (int) ($options['slot'] ?? 0);

// Same guards as the WP1/WP2 harnesses: loopback isolated fixtures only,
// worker-namespaced databases only.
if ($host !== '127.0.0.1') {
    $fail('Refusing a non-loopback host.');
}

if (($driver === 'pgsql' && $port !== 45432) || ($driver === 'mariadb' && $port !== 43306)) {
    $fail('Refusing a non-isolated database port.');
}

if (preg_match('/^share_rewards_test_[a-z0-9_]{1,43}$/', $database) !== 1) {
    $fail('Refusing a database outside the isolated worker namespace.');
}

if ($mode !== 'payout' || $readyDir === '' || ! in_array($slot, [0, 1], true)) {
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
    ];

config(['database.connections.smr_race_worker' => $workerConfig]);
config(['database.default' => 'smr_race_worker']);
config(['app.url' => (string) ($options['app-url'] ?? config('app.url'))]);
// Shared file cache (NOT array): the payout send mutex must exclude
// across the two worker processes, exactly as in production. The
// affiliate notification side channel is muted (as mail is): it is not
// the raced boundary, and its tables are outside the worker subset.
config([
    'cache.default' => 'file',
    'queue.default' => 'sync',
    'session.driver' => 'array',
    'mail.default' => 'array',
    'affiliates.notifications.enabled' => false,
]);
DB::purge('smr_race_worker');

OwnerContext::setForRequest(null);

try {
    DB::connection()->getPdo();

    if ($driver === 'pgsql') {
        DB::connection()->statement("SET TIME ZONE 'UTC'");
    }
} catch (Throwable $exception) {
    $fail('Worker database fixture is not reachable: '.$exception->getMessage());
}

// Deterministic start barrier: both workers arrive before either acts,
// so the two operations genuinely overlap. Bounded wait with an
// explicit failure; the race proof itself is the asserted end state,
// never timing.
$peer = $slot === 0 ? 1 : 0;

file_put_contents($readyDir.'/ready-'.$slot, (string) getmypid());

$deadline = microtime(true) + 60;

while (! is_file($readyDir.'/ready-'.$peer)) {
    if (microtime(true) > $deadline) {
        $fail('Race peer never arrived at the start barrier.');
    }

    usleep(5000);
}

// Controlled HTTP per worker: no live network, ever. Each worker counts
// its own provider POSTs so the parent can assert exactly-once sends.
$posts = 0;
$fake = $payload['fake'] ?? [];
$postSpecs = $fake['posts'] ?? [];
$listed = $fake['listed'] ?? [];

Http::preventStrayRequests();
Http::fake([
    'api.stripe.com/v1/transfers*' => function ($request) use (&$posts, $postSpecs, $listed) {
        if ($request->method() === 'GET') {
            return Http::response(['data' => $listed], 200);
        }

        $spec = $postSpecs[min($posts, count($postSpecs) - 1)] ?? ['status' => 200, 'body' => ['id' => 'tr_race_default']];
        $posts++;

        return Http::response($spec['body'] ?? [], $spec['status'] ?? 200);
    },
]);

config(['affiliates.payouts.stripe.secret_key' => 'sk_test_fixture']);

$job = $payload['slots'][$slot] ?? null;

if (! is_array($job) || ! is_string($job['op'] ?? null)) {
    $fail('Race payload carries no operation for this slot.');
}

$actor = $payload['actor'] ?? [];
$decided = CarbonImmutable::parse((string) ($payload['decided_at'] ?? '2026-06-18T00:00:00Z'));

try {
    $report = match ($job['op']) {
        'submit' => (static function () use ($payload, $actor, $decided): array {
            $request = SubmitRewardPayoutAction::run(
                (string) $payload['request_id'],
                (string) ($actor['type'] ?? 'user'),
                $actor['id'] ?? null,
                (string) ($actor['role'] ?? 'staff'),
                $decided,
            );

            return ['outcome' => 'done', 'status' => $request->status->value];
        })(),
        'cancel' => (static function () use ($payload, $actor, $decided): array {
            $request = CancelRewardPayoutAction::run(
                (string) $payload['request_id'],
                (string) ($actor['type'] ?? 'user'),
                $actor['id'] ?? null,
                (string) ($actor['role'] ?? 'staff'),
                (string) ($payload['reason'] ?? 'race-cancel'),
                $decided,
            );

            return ['outcome' => 'done', 'status' => $request->status->value];
        })(),
        'reconcile' => (static function () use ($payload, $actor, $decided): array {
            $request = ReconcileRewardPayoutAction::run(
                (string) $payload['request_id'],
                (string) ($actor['type'] ?? 'user'),
                $actor['id'] ?? null,
                (string) ($actor['role'] ?? 'staff'),
                $decided,
            );

            return ['outcome' => 'done', 'status' => $request->status->value];
        })(),
        'reverse' => (static function () use ($payload, $actor, $decided): array {
            $result = ReverseRewardFulfillmentAction::run(
                RewardAward::query()->whereKey((string) $payload['award_id'])->firstOrFail(),
                (string) ($actor['type'] ?? 'user'),
                $actor['id'] ?? null,
                (string) ($actor['role'] ?? 'staff'),
                (string) ($payload['reason'] ?? 'race-reverse'),
                null,
                $decided,
            );

            return ['outcome' => 'done', 'status' => $result['award']->status->value];
        })(),
        default => $fail('Unknown race operation.'),
    };
} catch (InvalidArgumentException|LogicException $exception) {
    // A clean refusal (lost race, wrong gate) is a REPORTED outcome, not
    // a worker crash: the parent asserts exactly one winner.
    $report = ['outcome' => 'aborted', 'message' => $exception->getMessage()];
} catch (Throwable $exception) {
    $fail('Race worker crashed: '.get_class($exception).': '.$exception->getMessage());
}

$report['posts'] = $posts;

echo json_encode(['ok' => true, 'report' => $report], JSON_THROW_ON_ERROR)."\n";
