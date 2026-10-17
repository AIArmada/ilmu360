<?php

declare(strict_types=1);

// Permanent ordered-recovery worker for the committed-share late-arrival
// proof. Executed only as a standalone OS process
// (`php CommittedShareLateArrivalWorker.php --database=... ...`); returns
// immediately when merely included, so test runners and autoloaders
// never execute it. Recovers ONE delayed outbound share through the
// REAL producer API on the isolated worker database, proving the
// late signal commits from an independent process/connection after
// the cursor already advanced elsewhere.

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Support\Share\CommittedShareSignalProducer;
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
    'touchpoint-id:',
]);

$fail = static function (string $message, int $exit = 1): never {
    echo json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR)."\n";

    exit($exit);
};

$database = (string) ($options['database'] ?? '');
$touchpointId = (string) ($options['touchpoint-id'] ?? '');

// Same guards as the WP1/WP2 harnesses: loopback isolated fixtures only,
// worker-namespaced databases only.


if (preg_match('/^share_rewards_test_[a-z0-9_]{1,43}$/', $database) !== 1) {
    $fail('Refusing a database outside the isolated worker namespace.');
}



$basePath = realpath(__DIR__.'/../../../');

if (! is_string($basePath) || ! is_file($basePath.'/bootstrap/app.php')) {
    $fail('Unable to locate the application base path.');
}

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$workerConfig = RealDriverFixture::connectionConfig($database);


config(['database.connections.csl_worker' => $workerConfig]);
config(['database.default' => 'csl_worker']);
config(['app.url' => (string) ($options['app-url'] ?? config('app.url'))]);
config([
    'cache.default' => 'array',
    'queue.default' => 'sync',
    'session.driver' => 'array',
    'mail.default' => 'array',
]);
DB::purge('csl_worker');

OwnerContext::setForRequest(null);

try {
    DB::connection()->getPdo();

    DB::connection()->statement("SET TIME ZONE 'UTC'");
} catch (Throwable $exception) {
    $fail('Worker database fixture is not reachable: '.$exception->getMessage());
}

try {
    $event = app(CommittedShareSignalProducer::class)->produce($touchpointId);

    $report = [
        'signal_event_id' => (string) $event->getKey(),
        'idempotency_key' => (string) $event->idempotency_key,
        'occurred_at' => CarbonImmutable::parse($event->occurred_at)->setTimezone('UTC')->toIso8601String(),
    ];
} catch (Throwable $exception) {
    $fail(get_class($exception).': '.$exception->getMessage());
}

echo json_encode(['ok' => true, 'report' => $report], JSON_THROW_ON_ERROR)."\n";
