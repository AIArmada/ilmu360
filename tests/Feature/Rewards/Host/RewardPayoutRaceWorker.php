<?php

declare(strict_types=1);

// Permanent race worker for RewardPayoutRaceTest. Executed only as a
// subprocess by ShareMetricRaceHarness; returns immediately when merely
// included, so test runners and autoloaders never execute it.

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Rewards\Actions\CancelRewardPayoutAction;
use AIArmada\Rewards\Actions\ConfirmRewardPayoutAction;
use AIArmada\Rewards\Actions\ReverseRewardFulfillmentAction;
use AIArmada\Rewards\Actions\SubmitRewardPayoutAction;
use AIArmada\Rewards\Models\RewardAward;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\RealDriverFixture;

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$options = getopt('', [
    'database:',
    'app-url:',
    'mode:',
    'ready-dir:',
    'slot:',
]);

$fail = static function (string $message, int $exit = 1): never {
    echo json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR)."\n";

    exit($exit);
};
$database = (string) ($options['database'] ?? '');
$mode = (string) ($options['mode'] ?? '');
$readyDir = (string) ($options['ready-dir'] ?? '');
$slot = (int) ($options['slot'] ?? 0);

// Same guards as the WP1/WP2 harnesses: loopback isolated fixtures only,
// worker-namespaced databases only.
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

$workerConfig = RealDriverFixture::connectionConfig($database);

config(['database.connections.smr_race_worker' => $workerConfig]);
config(['database.default' => 'smr_race_worker']);
config(['app.url' => (string) ($options['app-url'] ?? config('app.url'))]);
// Shared file cache (NOT array): the payout send mutex must exclude
// across the two worker processes, exactly as in production.
config([
    'cache.default' => 'file',
    'queue.default' => 'sync',
    'session.driver' => 'array',
    'mail.default' => 'array',
]);
DB::purge('smr_race_worker');

// Subprocesses do not inherit the parent's container bindings: opt into
// the native manual rail here, exactly as the parent setup does.

OwnerContext::setForRequest(null);

try {
    DB::connection()->getPdo();

    DB::connection()->statement("SET TIME ZONE 'UTC'");
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
        'confirm' => (static function () use ($payload, $actor, $decided): array {
            $request = ConfirmRewardPayoutAction::run(
                (string) $payload['request_id'],
                [
                    'bank_reference' => 'race-bank-reference',
                    'transferred_at' => '2026-06-19T00:00:00Z',
                ],
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

echo json_encode(['ok' => true, 'report' => $report], JSON_THROW_ON_ERROR)."\n";
