<?php

declare(strict_types=1);

// Late-arrival proof: an old outbound share delayed by a Signals
// outage is ingested AFTER a newer event already advanced the
// cursor. Normal draining must still project the old event exactly
// once without a manual reset; replays must not duplicate.

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Models\Event;
use App\Models\ShareCohortProjection;
use App\Models\User;
use App\Support\Share\CommittedShareSignalProducer;
use App\Support\Share\CommittedShareSignalReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sharer = User::factory()->create();
    $this->event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);

    config(['dawah-share.reporting.reporting_start' => '2026-05-01']);
});

function cslPayload(TestCase $testCase, User $user, string $url): array
{
    return $testCase->actingAs($user)->getJson(route('dawah-share.payload', [
        'url' => $url,
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();
}

function cslTrack(TestCase $testCase, User $user, string $token, string $provider): void
{
    $testCase->actingAs($user)->postJson(route('dawah-share.track'), [
        'provider' => $provider,
        'tracking_token' => $token,
    ])->assertNoContent();
}

function cslShare(TestCase $testCase, User $user, CarbonImmutable $at, ?string $url = null): AffiliateTouchpoint
{
    $payload = cslPayload($testCase, $user, $url ?? route('events.show', $testCase->event));

    Carbon::setTestNow(Carbon::parse($at->toIso8601String()));
    cslTrack($testCase, $user, (string) $payload['tracking_token'], 'copy_link');
    Carbon::setTestNow();

    return AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->latest('touched_at')->firstOrFail();
}

function cslFailingOnceIngestor(SignalEventIngestor $real): SignalEventIngestor
{
    return new class($real) implements SignalEventIngestor
    {
        public bool $failed = false;

        public function __construct(private readonly SignalEventIngestor $inner) {}

        public function handle(TrackedProperty $trackedProperty, array $payload, bool $trusted): SignalEvent
        {
            if (! $this->failed) {
                $this->failed = true;

                throw new RuntimeException('csl injected signals outage');
            }

            return $this->inner->handle($trackedProperty, $payload, $trusted);
        }
    };
}

it('projects a late older signal after a newer event drained, without reset', function (): void {
    $real = app(SignalEventIngestor::class);

    app()->singleton(SignalEventIngestor::class, fn (): SignalEventIngestor => cslFailingOnceIngestor($real));

    // Old share commits during the outage: the source persists with
    // its original time, but no signal is ingested yet.
    $oldPayload = cslPayload($this, $this->sharer, route('events.show', $this->event));

    Carbon::setTestNow(Carbon::parse('2026-05-10T08:00:00Z'));

    try {
        $this->actingAs($this->sharer)->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $oldPayload['tracking_token'],
        ]);

        $this->fail('The injected outage must surface.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('outage');
    } finally {
        Carbon::setTestNow();
    }

    $old = AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->firstOrFail();

    expect(CarbonImmutable::parse($old->touched_at)->toIso8601String())->toBe('2026-05-10T08:00:00+00:00')
        ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(0);

    // Newer share ingests normally while the old source waits.
    $new = cslShare($this, $this->sharer, CarbonImmutable::parse('2026-05-11T08:00:00Z'));

    expect(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(1);

    // Newer event drains first and advances the cursor past the old
    // occurrence time.
    $reader = new CommittedShareSignalReader;

    expect($reader->drain(25))->toBe(['processed' => 1, 'projected' => 1, 'failed' => 0])
        ->and(ShareCohortProjection::query()->count())->toBe(1)
        ->and(ShareCohortProjection::query()->where('business_key', 'committed-share:'.$new->getKey())->count())->toBe(1);

    // Outage recovery ingests the old source late, with its original
    // occurrence time preserved behind the cursor.
    $recovered = app(CommittedShareSignalProducer::class)->produce($old);

    expect(CarbonImmutable::parse($recovered->occurred_at)->toIso8601String())->toBe('2026-05-10T08:00:00+00:00')
        ->and($recovered->idempotency_key)->toBe('committed-share:'.$old->getKey())
        ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(2);

    // Normal drain projects the late old event exactly once — no reset.
    expect($reader->drain(25))->toBe(['processed' => 1, 'projected' => 1, 'failed' => 0])
        ->and(ShareCohortProjection::query()->count())->toBe(2)
        ->and(ShareCohortProjection::query()->where('business_key', 'committed-share:'.$old->getKey())->count())->toBe(1);

    // Quiet drain is a verified no-op; replay and duplicate recovery
    // never duplicate the projection.
    expect($reader->drain(25))->toBe(['processed' => 0, 'projected' => 0, 'failed' => 0]);

    $duplicate = app(CommittedShareSignalProducer::class)->produce($old);

    expect($duplicate->getKey())->toBe($recovered->getKey())
        ->and($reader->drain(25))->toBe(['processed' => 0, 'projected' => 0, 'failed' => 0])
        ->and(ShareCohortProjection::query()->count())->toBe(2);

    $reader->resetCursor();

    expect($reader->drain(25))->toBe(['processed' => 2, 'projected' => 2, 'failed' => 0])
        ->and(ShareCohortProjection::query()->count())->toBe(2);
});
