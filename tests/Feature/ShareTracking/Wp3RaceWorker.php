<?php

declare(strict_types=1);

// Permanent race worker for Wp3RealDriverRaceTest. Executed only as a
// standalone OS process (`php Wp3RaceWorker.php --database=... ...`); returns
// immediately when merely included, so test runners and autoloaders never
// execute it.

use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Services\ShareTrackingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
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


if (preg_match('/^share_rewards_test_[a-z0-9_]{1,43}$/', $database) !== 1) {
    $fail('Refusing a database outside the isolated worker namespace.');
}

if (! str_starts_with($database, 'share_rewards_test_wp3_')) {
    $fail('Refusing a database outside the WP3 worker namespace.');
}

if (! in_array($mode, ['claim', 'claim_solo', 'slug', 'slug_solo', 'guest_slug_solo', 'delete_solo'], true) || $readyDir === '' || ! in_array($slot, [0, 1], true)) {
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

$workerConfig = RealDriverFixture::connectionConfig($database);


config(['database.connections.wp3_race_worker' => $workerConfig]);
config(['database.default' => 'wp3_race_worker']);
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
DB::purge('wp3_race_worker');

// Mirror the Pest bootstrap: the worker is a bare console process, and
// owner-scoped production paths (including the user-deletion snapshot)
// require an explicit global context, exactly as in tests.
OwnerContext::setForRequest(null);

try {
    DB::connection()->getPdo();

    DB::connection()->statement("SET TIME ZONE 'UTC'");
} catch (Throwable $exception) {
    $fail('Worker database fixture is not reachable: '.$exception->getMessage());
}

// Deterministic start barrier: both workers arrive before either calls
// the service, so the two requests genuinely overlap. Bounded wait with
// an explicit failure; the race proof itself is the asserted end state,
// never timing. Solo workers skip the peer barrier: the parent
// orchestrates the forced overlap instead.
$solo = ($payload['solo'] ?? false) === true;

if (! $solo) {
    $peer = $slot === 0 ? 1 : 0;

    file_put_contents($readyDir.'/ready-'.$slot, (string) getmypid());

    $deadline = microtime(true) + 60;

    while (! is_file($readyDir.'/ready-'.$peer)) {
        if (microtime(true) > $deadline) {
            $fail('Race peer never arrived at the start barrier.');
        }

        usleep(5000);
    }
}

try {
    $report = match ($mode) {
        'claim' => (static function () use ($slot, $payload): array {
            $userIds = is_array($payload['user_ids'] ?? null) ? $payload['user_ids'] : [];
            $claimant = User::query()->whereKey((string) ($userIds[$slot] ?? ''))->firstOrFail();

            $result = app(ClaimGuestShareProfileAction::class)->handle(
                $claimant,
                (string) ($payload['capability'] ?? ''),
                true
            );

            return ['result' => $result->value];
        })(),
        'claim_solo' => (static function () use ($payload, $readyDir): array {
            $claimant = User::query()->whereKey((string) ($payload['user_id'] ?? ''))->firstOrFail();

            // Forced claim-vs-creation overlap: the parent holds the user
            // (or profile) lock while this worker attempts the real claim,
            // so the attempt structurally blocks until the parent commits.
            file_put_contents($readyDir.'/attempting-0', (string) microtime(true));

            $attemptedAt = microtime(true);

            $result = app(ClaimGuestShareProfileAction::class)->handle(
                $claimant,
                (string) ($payload['capability'] ?? ''),
                true
            );

            $returnedAt = microtime(true);

            return [
                'result' => $result->value,
                'attempted_at' => $attemptedAt,
                'returned_at' => $returnedAt,
            ];
        })(),
        'slug' => (static function () use ($payload): array {
            $sharer = User::query()->whereKey((string) ($payload['user_id'] ?? ''))->firstOrFail();

            // Current source, current migrations: concurrent creation for
            // the same owner and destination must converge on one link
            // and one managed short URL, never a duplicate row.
            $link = app(ShareTrackingService::class)->createOrReuseLink(
                $sharer,
                (string) ($payload['url'] ?? ''),
                'Majlis',
                'web'
            );

            // createOrReuseLink returns a ShareTrackingLinkData DTO whose id
            // is the affiliate link id; the public token lives on the
            // converged AffiliateLink row and is re-read from the same
            // worker database both racers share.
            $token = AffiliateLink::query()->whereKey($link->id)->value('custom_slug');

            return [
                'link_id' => $link->id,
                'token' => (string) $token,
            ];
        })(),
        'slug_solo' => (static function () use ($payload, $readyDir): array {
            $sharer = User::query()->whereKey((string) ($payload['user_id'] ?? ''))->firstOrFail();

            // Forced overlap: the parent holds the affiliate lock while
            // this worker attempts the same creation, so the lookup must
            // block and then reuse the parent's committed row.
            file_put_contents($readyDir.'/attempting-0', (string) microtime(true));

            $attemptedAt = microtime(true);

            $link = app(ShareTrackingService::class)->createOrReuseLink(
                $sharer,
                (string) ($payload['url'] ?? ''),
                'Majlis',
                'web'
            );

            $returnedAt = microtime(true);
            $row = AffiliateLink::query()->whereKey($link->id)->firstOrFail();

            return [
                'link_id' => $link->id,
                'token' => (string) $row->custom_slug,
                'url' => app(AffiliatesShareTrackingService::class)->shortUrlForLink($row),
                'attempted_at' => $attemptedAt,
                'returned_at' => $returnedAt,
            ];
        })(),
        'guest_slug_solo' => (static function () use ($payload, $readyDir): array {
            $request = Request::create((string) ($payload['url'] ?? ''), 'GET');
            $request->headers->set('X-Dawah-Guest-Credential', (string) ($payload['credential'] ?? ''));

            // Forced guest overlap: same protocol as the member leg, over
            // the anonymous credential transport.
            file_put_contents($readyDir.'/attempting-0', (string) microtime(true));

            $attemptedAt = microtime(true);

            $result = app(ShareTrackingService::class)->sharePayload(
                null,
                (string) ($payload['url'] ?? ''),
                'Majlis',
                'Majlis',
                'web',
                $request
            );

            $returnedAt = microtime(true);

            return [
                'link_id' => (string) AffiliateLink::query()->where('custom_slug', (string) $result['tracking_token'])->value('id'),
                'token' => (string) $result['tracking_token'],
                'url' => (string) $result['url'],
                'reissued_credential' => $result['guest_credential'] ?? null,
                'reissued_capability' => $result['guest_claim_capability'] ?? null,
                'attempted_at' => $attemptedAt,
                'returned_at' => $returnedAt,
            ];
        })(),
        'delete_solo' => (static function () use ($payload, $readyDir): array {
            $victim = User::query()->whereKey((string) ($payload['user_id'] ?? ''))->firstOrFail();

            // Forced claim-vs-delete overlap through the REAL production
            // model entry point: snapshot, deletion-hook cleanup, and
            // physical removal run exactly as production runs them. The
            // parent holds the user lock while this worker attempts the
            // deletion, so the attempt structurally blocks until the
            // parent commits.
            file_put_contents($readyDir.'/attempting-0', (string) microtime(true));

            $attemptedAt = microtime(true);

            $victim->delete();

            $returnedAt = microtime(true);

            return [
                'deleted' => true,
                'attempted_at' => $attemptedAt,
                'returned_at' => $returnedAt,
            ];
        })(),
    };
} catch (Throwable $exception) {
    $fail(get_class($exception).': '.$exception->getMessage());
}

echo json_encode(['ok' => true, 'slot' => $slot] + $report, JSON_THROW_ON_ERROR)."\n";

exit(0);
