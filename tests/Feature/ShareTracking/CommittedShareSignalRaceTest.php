<?php

declare(strict_types=1);

// R2 real-driver proof: two independent drain workers race the same
// committed-share signal log on the isolated PostgreSQL 17 and
// MariaDB 11.4 fixtures. The cursor fence + idempotent projections
// apply every signal exactly once with no stale overwrite: a stale
// worker can neither double-apply nor drag the cursor backwards.
// Timing is never asserted.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Signals\Models\SignalEvent;
use App\Models\ShareCohortProjection;
use App\Models\ShareSignalReaderCursor;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Share\CommittedShareSignalProducer;
use App\Support\Share\CommittedShareSignalReader;
use Illuminate\Http\Request;
use Tests\Feature\ShareTracking\ShareMetricRaceHarness;
use Tests\TestCase;

function cssDrainRaceCase(TestCase $testCase, string $driver): void
{
    [$database, $previous] = ShareMetricRaceHarness::worker($driver, 'cssdrain');

    try {
        $pageUrl = route('events.index');

        $member = User::factory()->create(['email' => 'css-drain@example.test']);
        $link = ShareMetricRaceHarness::link($member, $pageUrl);

        // Four committed shares through REAL ingestion; the observer
        // produces four real trusted signals inline (sync queue).
        foreach (['copy_link', 'whatsapp', 'telegram', 'threads'] as $provider) {
            app(ShareTrackingService::class)->recordShareAction(
                $provider,
                $member,
                (string) $link->custom_slug,
                Request::create($pageUrl, 'POST'),
            );
        }

        expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->count())->toBe(4)
            ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(4);

        [$first, $second] = ShareMetricRaceHarness::runRace([
            'driver' => $driver,
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'drain',
            'payload' => ['batch' => 25],
        ]);

        expect($first['failed'] ?? 1)->toBe(0)
            ->and($second['failed'] ?? 1)->toBe(0)
            ->and(($first['processed'] ?? 0) + ($second['processed'] ?? 0))->toBeGreaterThanOrEqual(4)
            ->and(ShareCohortProjection::query()->count())->toBe(4)
            ->and(ShareCohortProjection::query()->distinct()->count('business_key'))->toBe(4);

        $cursor = ShareSignalReaderCursor::query()
            ->where('reader_name', CommittedShareSignalReader::READER_NAME)
            ->firstOrFail();

        // Every signal applied exactly once; the cursor sits at the end.
        expect((int) $cursor->processed_total)->toBe(4)
            ->and($cursor->last_signal_event_id)->not->toBeNull();

        // A quiet drain afterwards is a verified no-op: no ghost backlog.
        expect(app(CommittedShareSignalReader::class)->drain(25))
            ->toBe(['processed' => 0, 'projected' => 0, 'failed' => 0]);
    } finally {
        ShareMetricRaceHarness::teardown($driver, $database, $previous);
    }
}

foreach (['pgsql', 'mariadb'] as $cssDriver) {
    $label = $cssDriver === 'pgsql' ? 'isolated PostgreSQL' : 'isolated MariaDB';

    it("fences concurrent committed-share drains to exactly-once projections on {$label}", function () use ($cssDriver): void {
        cssDrainRaceCase($this, $cssDriver);
    });
}
