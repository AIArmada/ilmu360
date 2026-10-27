<?php

declare(strict_types=1);

// Permanent race worker for Wp2RealDriverRaceTest. Executed only as a
// standalone OS process (`php Wp2RaceWorker.php --database=... ...`); returns
// immediately when merely included, so test runners and autoloaders never
// execute it.

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Ticketing\Models\TicketType;
use App\Actions\Events\Commerce\PrepareEventCheckoutAction;
use App\Actions\ShareTracking\DispatchShareEffectReceiptsAction;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
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

if (! in_array($mode, ['outcome', 'landing', 'landing_ordered', 'dispatch', 'correction', 'inherit_correction', 'prepare'], true) || $readyDir === '' || ! in_array($slot, [0, 1], true)) {
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

config(['database.connections.wp2_race_worker' => $workerConfig]);
config(['database.default' => 'wp2_race_worker']);
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
DB::purge('wp2_race_worker');

try {
    DB::connection()->getPdo();

    DB::connection()->statement("SET TIME ZONE 'UTC'");
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

$buildRequest = static function (?string $cookie): Request {
    $request = Request::create((string) config('app.url'), 'GET');

    if (is_string($cookie) && $cookie !== '') {
        $request->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), $cookie);
    }

    return $request;
};

try {
    // This standalone process has no HTTP middleware to establish the
    // public share flow's explicit global owner context.
    $report = OwnerContext::withOwner(null, fn (): array => match ($mode) {
        'outcome', 'correction' => (static function () use ($mode, $slot, $payload, $buildRequest): array {
            if ($mode === 'correction' && $slot === 1) {
                $correction = app(ReverseShareOutcomeAction::class)->handle(
                    (string) ($payload['business_key'] ?? ''),
                    'wp2_race_correction',
                    null,
                    null,
                );

                return [
                    'correction_id' => $correction instanceof ShareOutcomeCorrection
                        ? (string) $correction->getKey()
                        : null,
                ];
            }

            $type = DawahShareOutcomeType::from((string) ($payload['type'] ?? ''));
            ShareOutcomeBusinessKey::parse((string) ($payload['business_key'] ?? ''), $type);

            $actor = User::query()->whereKey((string) ($payload['actor_id'] ?? ''))->firstOrFail();
            $cookies = is_array($payload['cookies'] ?? null) ? $payload['cookies'] : [];
            $cookie = $cookies[$slot] ?? null;

            $outcome = app(ShareTrackingService::class)->recordOutcome(
                type: $type,
                businessKey: (string) $payload['business_key'],
                subject: null,
                actor: $actor,
                request: $buildRequest(is_string($cookie) ? $cookie : null),
                metadata: ['wp2_race_slot' => $slot],
            );

            $receipt = ShareSourceAttributionReceipt::query()
                ->where('business_key', (string) $payload['business_key'])
                ->firstOrFail();

            return [
                'receipt_id' => (string) $receipt->getKey(),
                'attributed' => $receipt->isAttributed(),
                'outcome_null' => $outcome === null,
            ];
        })(),
        'landing', 'landing_ordered' => (static function () use ($mode, $payload, $slot, $readyDir, $fail): array {
            // Ordered landings serialize commit order deterministically:
            // each worker lands only after the parent releases its turn
            // file, then records completion. The landing itself is the
            // same real captureRequest call as the concurrent mode.
            if ($mode === 'landing_ordered') {
                $turnFile = $readyDir.'/turn-'.$slot;
                $deadline = microtime(true) + 90;

                while (! is_file($turnFile)) {
                    if (microtime(true) > $deadline) {
                        $fail('Ordered landing turn never arrived.');
                    }

                    usleep(5000);
                }
            }

            $urls = is_array($payload['urls'] ?? null) ? $payload['urls'] : [];
            $request = Request::create((string) ($urls[$slot] ?? ''), 'GET');
            $route = app('router')->getRoutes()->match($request);
            $request->setRouteResolver(static fn (): mixed => $route);

            $cookie = (string) ($payload['cookie'] ?? '');

            if ($cookie !== '') {
                $request->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), $cookie);
            }

            $encoded = app(ShareTrackingService::class)->captureRequest($request);

            if ($mode === 'landing_ordered') {
                file_put_contents($readyDir.'/done-'.$slot, (string) getmypid());
            }

            return ['cookie_returned' => is_string($encoded) && $encoded !== ''];
        })(),
        'dispatch' => (static function () use ($slot): array {
            $processed = app(DispatchShareEffectReceiptsAction::class)->handle(50, 'wp2-race-worker-'.$slot);

            return ['processed' => $processed];
        })(),
        'inherit_correction' => (static function () use ($slot, $payload): array {
            if ($slot === 1) {
                $sourceCorrection = app(ReverseShareOutcomeAction::class)->handle(
                    (string) ($payload['source_key'] ?? ''),
                    'wp2_race_correction',
                    null,
                    null,
                );

                $childCorrection = app(ReverseShareOutcomeAction::class)->handle(
                    (string) ($payload['child_key'] ?? ''),
                    'wp2_race_correction',
                    null,
                    null,
                );

                return [
                    'source_correction_id' => $sourceCorrection instanceof ShareOutcomeCorrection
                        ? (string) $sourceCorrection->getKey()
                        : null,
                    'child_correction_id' => $childCorrection instanceof ShareOutcomeCorrection
                        ? (string) $childCorrection->getKey()
                        : null,
                ];
            }

            $actor = User::query()->whereKey((string) ($payload['actor_id'] ?? ''))->firstOrFail();

            $outcome = app(ShareTrackingService::class)->recordInheritedOutcome(
                type: DawahShareOutcomeType::EventCheckin,
                businessKey: (string) $payload['child_key'],
                sourceBusinessKey: (string) $payload['source_key'],
                subject: null,
                actor: $actor,
                metadata: ['wp2_race_slot' => $slot],
            );

            $receipt = ShareSourceAttributionReceipt::query()
                ->where('business_key', (string) $payload['child_key'])
                ->firstOrFail();

            return [
                'receipt_id' => (string) $receipt->getKey(),
                'attributed' => $receipt->isAttributed(),
                'outcome_null' => $outcome === null,
            ];
        })(),
        'prepare' => (static function () use ($payload, $buildRequest): array {
            config()->set('events.features.commerce.public_paid_checkout_enabled', true);

            // TicketType carries an owner scope: resolve fixtures and run
            // the real checkout action inside the same global context the
            // in-process retry uses.
            return OwnerContext::withOwner(null, static function () use ($payload, $buildRequest): array {
                $event = Event::query()->whereKey((string) ($payload['event_id'] ?? ''))->firstOrFail();
                $ticket = TicketType::query()->whereKey((string) ($payload['ticket_id'] ?? ''))->firstOrFail();
                $buyer = User::query()->whereKey((string) ($payload['buyer_id'] ?? ''))->firstOrFail();
                $cookie = $payload['cookie'] ?? null;
                $participants = is_array($payload['participants'] ?? null) ? $payload['participants'] : [];

                $session = app(PrepareEventCheckoutAction::class)->handle(
                    event: $event,
                    ticketType: $ticket,
                    quantity: (int) ($payload['quantity'] ?? 1),
                    participants: $participants,
                    buyer: $buyer,
                    agreement: ['version' => 'event-participation-v1'],
                    discountCode: null,
                    idempotencyKey: (string) ($payload['key'] ?? ''),
                    request: $buildRequest(is_string($cookie) ? $cookie : null),
                );

                return [
                    'session_id' => (string) $session->getKey(),
                    'attribution_id' => data_get($session->payment_data, 'share_provenance.attribution_id'),
                ];
            });
        })(),
    });
} catch (Throwable $exception) {
    $fail(get_class($exception).': '.$exception->getMessage());
}

echo json_encode(['ok' => true, 'slot' => $slot] + $report, JSON_THROW_ON_ERROR)."\n";

exit(0);
