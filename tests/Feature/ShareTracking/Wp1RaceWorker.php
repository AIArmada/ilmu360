<?php

declare(strict_types=1);

// Permanent race worker for Wp1RealDriverCollisionTest. Executed only as a
// standalone OS process (`php Wp1RaceWorker.php --database=... ...`); returns
// immediately when merely included, so test runners and autoloaders never
// execute it.

use App\Models\User;
use App\Services\ShareTrackingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\RealDriverFixture;

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$options = getopt('', [
    'database:',
    'user-id:',
    'url:',
    'title:',
    'app-url:',
    'ready-dir:',
    'slot:',
]);

$fail = static function (string $message, int $exit = 1): never {
    echo json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR)."\n";

    exit($exit);
};

$database = (string) ($options['database'] ?? '');
$userId = (string) ($options['user-id'] ?? '');
$url = (string) ($options['url'] ?? '');
$title = (string) ($options['title'] ?? '');
$readyDir = (string) ($options['ready-dir'] ?? '');
$slot = (int) ($options['slot'] ?? 0);


if (preg_match('/^share_rewards_test_[a-z0-9_]{1,43}$/', $database) !== 1) {
    $fail('Refusing a database outside the isolated worker namespace.');
}

if ($userId === '' || $url === '' || $readyDir === '' || ! in_array($slot, [0, 1], true)) {
    $fail('Missing race job arguments.');
}

$basePath = realpath(__DIR__.'/../../../');

if (! is_string($basePath) || ! is_file($basePath.'/bootstrap/app.php')) {
    $fail('Unable to locate the application base path.');
}

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$workerConfig = RealDriverFixture::connectionConfig($database);


config(['database.connections.wp1_race_worker' => $workerConfig]);
config(['database.default' => 'wp1_race_worker']);
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
DB::purge('wp1_race_worker');

try {
    DB::connection()->getPdo();
    $user = User::query()->whereKey($userId)->firstOrFail();
} catch (Throwable $exception) {
    $fail('Worker database fixture is not reachable: '.$exception->getMessage());
}

// Deterministic start barrier: both workers arrive before either calls
// the service, so the two creator requests genuinely overlap. Bounded
// wait with an explicit failure; the race proof itself is the asserted
// end state (one winner, no duplicate), never timing.
$peer = $slot === 0 ? 1 : 0;

file_put_contents($readyDir.'/ready-'.$slot, (string) getmypid());

$deadline = microtime(true) + 60;

while (! is_file($readyDir.'/ready-'.$peer)) {
    if (microtime(true) > $deadline) {
        $fail('Race peer never arrived at the start barrier.');
    }

    usleep(5000);
}

try {
    $link = app(ShareTrackingService::class)->createOrReuseLink($user, $url, $title, 'web');
} catch (Throwable $exception) {
    $fail(get_class($exception).': '.$exception->getMessage());
}

echo json_encode([
    'ok' => true,
    'slot' => $slot,
    'link_id' => $link->id,
    'subject_key' => $link->subjectKey,
    'destination_url' => $link->destinationUrl,
], JSON_THROW_ON_ERROR)."\n";

exit(0);
