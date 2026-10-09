<?php

declare(strict_types=1);

// Permanent race worker for ShareMetricRaceTest. Executed only as a
// standalone OS process (`php ShareMetricRaceWorker.php --driver=...
// ...`); returns immediately when merely included, so test runners and
// autoloaders never execute it.

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Enums\DawahShareOutcomeType;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Share\CommittedShareSignalReader;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
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

if (! in_array($mode, ['share', 'outcome', 'landing', 'drain'], true) || $readyDir === '' || ! in_array($slot, [0, 1], true)) {
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

$basePath = realpath(__DIR__.'/../../../');

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
// Same URL base as the parent test process so canonical destinations match.
config(['app.url' => (string) ($options['app-url'] ?? config('app.url'))]);
// The worker is a bare console process outside phpunit.xml: pin the same
// non-persistent testing stores so no stray table is ever required.
config([
    'cache.default' => 'array',
    'queue.default' => 'sync',
    'session.driver' => 'array',
    'mail.default' => 'array',
]);
DB::purge('smr_race_worker');

// The bare worker boots outside phpunit: pin the same global null owner
// context the test process sets in Pest.php, so owner-scoped reads
// (reward evaluation listeners included) behave identically.
OwnerContext::setForRequest(null);

try {
    DB::connection()->getPdo();

    if ($driver === 'pgsql') {
        DB::connection()->statement("SET TIME ZONE 'UTC'");
    }
} catch (Throwable $exception) {
    $fail('Worker database fixture is not reachable: '.$exception->getMessage());
}

// Deterministic start barrier: both workers arrive before either calls
// the service, so the two requests genuinely overlap. Bounded wait with
// an explicit failure; the race proof itself is the asserted end state,
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

$cookieName = (string) config('dawah-share.cookie.name', 'mi_dawah_share');

try {
    $report = match ($mode) {
        'share' => (static function () use ($payload, $slot): array {
            $userId = $payload['slots'][$slot]['user_id'] ?? null;
            $user = is_string($userId) && $userId !== ''
                ? User::query()->whereKey($userId)->firstOrFail()
                : null;

            $request = Request::create((string) config('app.url'), 'POST');
            $credential = $payload['slots'][$slot]['guest_credential'] ?? null;

            if (is_string($credential) && $credential !== '') {
                $request->request->set(
                    (string) config('dawah-share.guest_credential.input', 'guest_credential'),
                    $credential
                );
            }

            $provider = $payload['slots'][$slot]['provider'] ?? $payload['provider'] ?? 'copy_link';

            app(ShareTrackingService::class)->recordShareAction(
                (string) $provider,
                $user,
                (string) ($payload['tracking_token'] ?? ''),
                $request,
            );

            return ['shared' => true];
        })(),
        'outcome' => (static function () use ($payload, $slot, $cookieName): array {
            $type = DawahShareOutcomeType::from((string) ($payload['type'] ?? ''));
            $businessKey = (string) ($payload['business_key'] ?? '');
            ShareOutcomeBusinessKey::parse($businessKey, $type);

            $actor = User::query()->whereKey((string) ($payload['actor_id'] ?? ''))->firstOrFail();

            $request = Request::create((string) config('app.url'), 'GET');
            $cookie = $payload['cookies'][$slot] ?? null;

            if (is_string($cookie) && $cookie !== '') {
                $request->cookies->set($cookieName, $cookie);
            }

            $data = app(ShareTrackingService::class)->recordOutcome(
                $type,
                $businessKey,
                null,
                $actor,
                $request,
            );

            $receipt = ShareSourceAttributionReceipt::query()
                ->where('business_key', $businessKey)
                ->first();

            return [
                'outcome_id' => $data?->id,
                'attributed' => $receipt instanceof ShareSourceAttributionReceipt && $receipt->isAttributed(),
            ];
        })(),
        'landing' => (static function () use ($payload, $slot, $cookieName): array {
            $request = Request::create((string) ($payload['slots'][$slot]['url'] ?? config('app.url')), 'GET');

            $route = app('router')->getRoutes()->match($request);
            $request->setRouteResolver(static fn (): mixed => $route);

            $cookie = $payload['slots'][$slot]['cookie'] ?? null;

            if (is_string($cookie) && $cookie !== '') {
                $request->cookies->set($cookieName, $cookie);
            }

            return ['encoded' => app(ShareTrackingService::class)->captureRequest($request)];
        })(),
        'drain' => (static function () use ($payload): array {
            $report = app(CommittedShareSignalReader::class)->drain((int) ($payload['batch'] ?? 25));

            return [
                'processed' => $report['processed'],
                'projected' => $report['projected'],
                'failed' => $report['failed'],
            ];
        })(),
    };
} catch (Throwable $exception) {
    $fail(get_class($exception).': '.$exception->getMessage()."\n".$exception->getTraceAsString());
}

echo json_encode(['ok' => true, 'report' => $report], JSON_THROW_ON_ERROR)."\n";
