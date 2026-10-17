<?php

declare(strict_types=1);

// Late-arrival real-driver proof: on the isolated PostgreSQL 17, an
// older source delayed by a Signals outage is recovered from an
// INDEPENDENT OS process through the real producer API. Normal
// draining (here raced by two concurrent drain workers) must project
// the late old event exactly once without a manual reset; replays
// and duplicate recoveries must not duplicate. Timing is never
// asserted — only the committed end state.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Models\ShareCohortProjection;
use App\Models\ShareSignalReaderCursor;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Share\CommittedShareSignalProducer;
use App\Support\Share\CommittedShareSignalReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Tests\Feature\ShareTracking\ShareMetricRaceHarness;
use Tests\Support\RealDriverFixture;
use Tests\TestCase;

function cslaFailingOnceIngestor(SignalEventIngestor $real): SignalEventIngestor
{
    return new class($real) implements SignalEventIngestor
    {
        public bool $failed = false;

        public function __construct(private readonly SignalEventIngestor $inner) {}

        public function handle(TrackedProperty $trackedProperty, array $payload, bool $trusted): SignalEvent
        {
            if (! $this->failed) {
                $this->failed = true;

                throw new RuntimeException('csla injected signals outage');
            }

            return $this->inner->handle($trackedProperty, $payload, $trusted);
        }
    };
}

/**
 * @return array{signal_event_id: string, idempotency_key: string, occurred_at: string}
 */
function cslaRecoverInWorkerProcess(string $database, string $touchpointId): array
{
    $worker = __DIR__.'/CommittedShareLateArrivalWorker.php';

    expect($worker)->toBeFile();

    $process = new Process(
        command: [
            PHP_BINARY,
            $worker,
            '--database='.$database,
            '--app-url='.(string) config('app.url'),
            '--touchpoint-id='.$touchpointId,
        ],
        env: ['APP_ENV' => 'testing'],
        timeout: 150,
    );

    $process->run();

    $output = trim($process->getOutput());
    $decoded = json_decode($output, true);

    expect($process->getExitCode())->toBe(0, 'Late-arrival worker failed: '.$output.' '.$process->getErrorOutput())
        ->and($decoded)->toBeArray()
        ->and((bool) ($decoded['ok'] ?? false))->toBeTrue('Late-arrival worker reported failure: '.$output);

    $report = $decoded['report'] ?? [];

    expect($report)->toBeArray()
        ->and($report['signal_event_id'] ?? null)->not->toBeNull()
        ->and($report['idempotency_key'] ?? null)->not->toBeNull()
        ->and($report['occurred_at'] ?? null)->not->toBeNull();

    return $report;
}

function cslaAssertConcurrentFixtureBootstrap(): void
{
    static $verified = false;

    if ($verified) {
        return;
    }

    $verified = true;

    $worker = __DIR__.'/RealDriverFixtureColdStartWorker.php';
    $readyDirectory = sys_get_temp_dir().'/real-driver-cold-start-'.bin2hex(random_bytes(8));

    expect($worker)->toBeFile()
        ->and(mkdir($readyDirectory, 0700))->toBeTrue();

    $processes = [];

    try {
        foreach (range(0, 7) as $slot) {
            $process = new Process(
                command: [
                    PHP_BINARY,
                    $worker,
                    '--ready-dir='.$readyDirectory,
                    '--slot='.$slot,
                ],
                env: ['APP_ENV' => 'testing'],
                timeout: 180,
            );

            $process->start();
            $processes[$slot] = $process;
        }

        $deadline = microtime(true) + 60;

        while (count(glob($readyDirectory.'/ready-*') ?: []) < count($processes)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Fixture bootstrap workers did not reach the start barrier.');
            }

            usleep(5000);
        }

        file_put_contents($readyDirectory.'/release', 'ready');

        foreach ($processes as $process) {
            $process->wait();
            $output = trim($process->getOutput());

            expect($process->getExitCode())->toBe(0, 'Fixture bootstrap worker failed: '.$output.' '.$process->getErrorOutput());

            $decoded = json_decode($output, true);

            expect($decoded)->toBeArray()
                ->and($decoded['ok'] ?? false)->toBeTrue()
                ->and($decoded['drivers'] ?? [])->toBe(['pgsql']);
        }
    } finally {
        file_put_contents($readyDirectory.'/release', 'ready');

        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        foreach (glob($readyDirectory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($readyDirectory);
    }
}

function cslaLateArrivalCase(TestCase $testCase): void
{
    cslaAssertConcurrentFixtureBootstrap();

    [$database, $previous] = ShareMetricRaceHarness::worker('cslate');

    try {
        $pageUrl = route('events.index');

        $member = User::factory()->create(['email' => 'csla-late@example.test']);
        $link = ShareMetricRaceHarness::link($member, $pageUrl);

        $real = app(SignalEventIngestor::class);

        app()->singleton(SignalEventIngestor::class, fn (): SignalEventIngestor => cslaFailingOnceIngestor($real));

        // Old share commits during the outage: source persists, signal missing.
        $testCase->travelTo(CarbonImmutable::parse('2026-05-10T08:00:00Z'));

        try {
            app(ShareTrackingService::class)->recordShareAction(
                'copy_link',
                $member,
                (string) $link->custom_slug,
                Request::create($pageUrl, 'POST'),
            );

            $testCase->fail('The injected outage must surface.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toContain('outage');
        } finally {
            $testCase->travelBack();
        }

        $old = AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->firstOrFail();

        expect(CarbonImmutable::parse($old->touched_at)->setTimezone('UTC')->toIso8601String())->toBe('2026-05-10T08:00:00+00:00')
            ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(0);

        // Newer share ingests normally while the old source waits.
        $testCase->travelTo(CarbonImmutable::parse('2026-05-11T08:00:00Z'));

        try {
            app(ShareTrackingService::class)->recordShareAction(
                'whatsapp',
                $member,
                (string) $link->custom_slug,
                Request::create($pageUrl, 'POST'),
            );
        } finally {
            $testCase->travelBack();
        }

        $new = AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->latest('touched_at')->firstOrFail();

        expect(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(1);

        // Newer event drains first from this process, advancing the cursor.
        $reader = app(CommittedShareSignalReader::class);

        expect($reader->drain(25))->toBe(['processed' => 1, 'projected' => 1, 'failed' => 0])
            ->and(ShareCohortProjection::query()->count())->toBe(1);

        // Ordered recovery: the old source is ingested late from an
        // INDEPENDENT process after the cursor already advanced. The
        // worker commits through the real producer API on its own
        // connection; this process waits for that commit before draining.
        $report = cslaRecoverInWorkerProcess($database, (string) $old->getKey());

        expect($report['occurred_at'])->toBe('2026-05-10T08:00:00+00:00')
            ->and($report['idempotency_key'])->toBe('committed-share:'.$old->getKey())
            ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(2);

        // Two concurrent drains race the late backlog: exactly-once
        // projection, no stale overwrite, no duplicate.
        [$first, $second] = ShareMetricRaceHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'drain',
            'payload' => ['batch' => 25],
        ]);

        expect($first['failed'] ?? 1)->toBe(0)
            ->and($second['failed'] ?? 1)->toBe(0)
            ->and(($first['projected'] ?? 0) + ($second['projected'] ?? 0))->toBe(1)
            ->and(ShareCohortProjection::query()->count())->toBe(2)
            ->and(ShareCohortProjection::query()->distinct()->count('business_key'))->toBe(2)
            ->and(ShareCohortProjection::query()->where('business_key', 'committed-share:'.$old->getKey())->count())->toBe(1);

        $cursor = ShareSignalReaderCursor::query()
            ->where('reader_name', CommittedShareSignalReader::READER_NAME)
            ->firstOrFail();

        expect((int) $cursor->processed_total)->toBe(2);

        // Quiet drain is a verified no-op; duplicate recovery never duplicates.
        expect(app(CommittedShareSignalReader::class)->drain(25))
            ->toBe(['processed' => 0, 'projected' => 0, 'failed' => 0]);

        $duplicate = app(CommittedShareSignalProducer::class)->produce((string) $old->getKey());

        expect($duplicate->getKey())->toBe($report['signal_event_id'])
            ->and(app(CommittedShareSignalReader::class)->drain(25))
            ->toBe(['processed' => 0, 'projected' => 0, 'failed' => 0])
            ->and(ShareCohortProjection::query()->count())->toBe(2);

        // Operator replay re-reads the retained log without duplicating.
        app(CommittedShareSignalReader::class)->resetCursor();

        expect(app(CommittedShareSignalReader::class)->drain(25))
            ->toBe(['processed' => 2, 'projected' => 2, 'failed' => 0])
            ->and(ShareCohortProjection::query()->count())->toBe(2);
    } finally {
        ShareMetricRaceHarness::teardown($database, $previous);
    }
}

$label = 'isolated PostgreSQL';
    it("recovers a late older signal from an independent process on {$label}", function (): void {
        cslaLateArrivalCase($this);
    });
