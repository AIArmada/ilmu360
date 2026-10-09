<?php

declare(strict_types=1);

// R2 proof: ONE named committed outbound-share Signals producer through
// the INSTALLED Signals package ingestor, and the ACTUAL reader of the
// package's durable signal log — with semantic keys, explicit effect
// ownership, isolated cursor recovery, and fenced final writes. A
// Signals outage can never roll back the committed source; later
// recovery applies once.

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Actions\ShareTracking\DispatchShareEffectReceiptsAction;
use App\Enums\DawahShareOutcomeType;
use App\Enums\ShareEffectStatus;
use App\Models\Event;
use App\Models\ShareCohortProjection;
use App\Models\ShareEffectReceipt;
use App\Models\ShareSignalReaderCursor;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Share\CommittedShareSignalProducer;
use App\Support\Share\CommittedShareSignalReader;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

function cssPayload(TestCase $testCase, User $user, string $url): array
{
    return $testCase->actingAs($user)->getJson(route('dawah-share.payload', [
        'url' => $url,
        'text' => 'Share this page',
        'title' => 'Shared Page',
    ]))->assertOk()->json();
}

function cssTrack(TestCase $testCase, User $user, string $token, string $provider): void
{
    $testCase->actingAs($user)->postJson(route('dawah-share.track'), [
        'provider' => $provider,
        'tracking_token' => $token,
    ])->assertNoContent();
}

function cssLandingCookie(TestCase $testCase, string $sharedUrl): string
{
    $cookie = $testCase->followingRedirects()->get($sharedUrl)->assertOk()
        ->getCookie((string) config('dawah-share.cookie.name'));

    expect($cookie)->not->toBeNull();

    return (string) $cookie?->getValue();
}

function cssShare(TestCase $testCase, User $user, CarbonImmutable $at, ?string $url = null): AffiliateTouchpoint
{
    $payload = cssPayload($testCase, $user, $url ?? route('events.show', $testCase->event));

    Carbon::setTestNow(Carbon::parse($at->toIso8601String()));
    cssTrack($testCase, $user, (string) $payload['tracking_token'], 'copy_link');
    Carbon::setTestNow();

    return AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->latest('touched_at')->firstOrFail();
}

function cssFailingOnceIngestor(SignalEventIngestor $real): SignalEventIngestor
{
    return new class($real) implements SignalEventIngestor
    {
        public bool $failed = false;

        public function __construct(private readonly SignalEventIngestor $inner) {}

        public function handle(TrackedProperty $trackedProperty, array $payload, bool $trusted): SignalEvent
        {
            if (! $this->failed) {
                $this->failed = true;

                throw new RuntimeException('css injected signals outage');
            }

            return $this->inner->handle($trackedProperty, $payload, $trusted);
        }
    };
}

it('produces one trusted signal per committed outbound share through the installed package', function (): void {
    $touchpoint = cssShare($this, $this->sharer, CarbonImmutable::parse('2026-05-10T08:00:00Z'));

    $events = SignalEvent::query()
        ->where('event_name', CommittedShareSignalProducer::EVENT_NAME)
        ->get();

    expect($events)->toHaveCount(1);

    $event = $events->sole();

    expect($event->ingestion_source)->toBe(SignalEvent::INGESTION_SOURCE_TRUSTED)
        ->and($event->event_category)->toBe(CommittedShareSignalProducer::EVENT_CATEGORY)
        ->and($event->idempotency_key)->toBe('committed-share:'.$touchpoint->getKey())
        ->and($event->source_event_id)->toBe('committed-share:'.$touchpoint->getKey())
        ->and(CarbonImmutable::parse($event->occurred_at)->toIso8601String())->toBe('2026-05-10T08:00:00+00:00')
        ->and($event->properties['channel'] ?? null)->toBe('copy_link')
        ->and($event->properties['cohort_week'] ?? null)->toBe('2026-05-04')
        ->and($event->properties['occurred_day'] ?? null)->toBe('2026-05-10')
        ->and($event->properties['definition_version'] ?? null)->not->toBeNull()
        ->and(array_keys($event->properties ?? []))->toEqualCanonicalizing(['channel', 'cohort_week', 'occurred_day', 'definition_version'])
        ->and((string) json_encode($event->properties))->not->toContain((string) $this->sharer->getKey());

    // Retry is a no-op: the semantic key deduplicates to the same event.
    $replayed = app(CommittedShareSignalProducer::class)->produce($touchpoint);

    expect($replayed->getKey())->toBe($event->getKey())
        ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(1);
});

it('ignores visits and non-share touchpoints', function (): void {
    $payload = cssPayload($this, $this->sharer, route('events.show', $this->event));
    cssLandingCookie($this, (string) $payload['url']);

    expect(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(1)
        ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(0);
});

it('fails loudly without a tracked property but keeps the committed source', function (): void {
    TrackedProperty::query()->delete();

    $payload = cssPayload($this, $this->sharer, route('events.show', $this->event));

    try {
        $this->actingAs($this->sharer)->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $payload['tracking_token'],
        ]);

        $this->fail('Producing without a tracked property must throw.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('tracked property');
    }

    $touchpoint = AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->firstOrFail();

    // The source committed; the signal is missing and recoverable.
    expect(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(0);

    TrackedProperty::query()->create([
        'slug' => 'ilmu360',
        'name' => 'Recovered Website',
        'write_key' => Str::random(40),
        'domain' => 'localhost',
        'type' => 'website',
        'timezone' => 'UTC',
        'currency' => 'MYR',
        'is_active' => true,
    ]);

    $recovered = app(CommittedShareSignalProducer::class)->produce($touchpoint);

    expect($recovered->idempotency_key)->toBe('committed-share:'.$touchpoint->getKey())
        ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(1);
});

it('survives a signals outage without rolling back the source and recovers once', function (): void {
    $real = app(SignalEventIngestor::class);

    app()->singleton(SignalEventIngestor::class, fn (): SignalEventIngestor => cssFailingOnceIngestor($real));

    $payload = cssPayload($this, $this->sharer, route('events.show', $this->event));

    try {
        $this->actingAs($this->sharer)->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => (string) $payload['tracking_token'],
        ]);

        $this->fail('The injected outage must surface.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('outage');
    }

    $touchpoint = AffiliateTouchpoint::query()->where('touchpoint_type', 'outbound_share')->firstOrFail();

    expect(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(0);

    // Later recovery applies exactly once, and a duplicate retry is a no-op.
    $first = app(CommittedShareSignalProducer::class)->produce($touchpoint);
    $second = app(CommittedShareSignalProducer::class)->produce($touchpoint);

    expect($second->getKey())->toBe($first->getKey())
        ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(1);
});

it('projects signals into cohort stages and resumes after partial drains', function (): void {
    $bareEvent = Event::factory()->create(['status' => 'approved', 'visibility' => 'public']);

    $first = cssShare($this, $this->sharer, CarbonImmutable::parse('2026-05-10T08:00:00Z'));
    $second = cssShare($this, $this->sharer, CarbonImmutable::parse('2026-05-11T08:00:00Z'), route('events.show', $bareEvent));

    // Accepted downstream evidence on the first share's link settles its
    // stage to participated at projection time. Landing and outcome share
    // one clock so the browser selection is live when the outcome lands.
    $payload = cssPayload($this, $this->sharer, route('events.show', $this->event));
    Carbon::setTestNow(Carbon::parse('2026-05-12T08:00:00Z'));
    $cookie = cssLandingCookie($this, (string) $payload['url']);

    $actor = User::factory()->create();
    $request = Request::create('/noop', 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), $cookie);
    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventCheckin,
        ShareOutcomeBusinessKey::for(DawahShareOutcomeType::EventCheckin, 'checkin:'.((string) Str::uuid())),
        null,
        $actor,
        $request,
        [],
        CarbonImmutable::parse('2026-05-12T08:00:00Z'),
    );
    Carbon::setTestNow();

    $reader = new CommittedShareSignalReader;

    // Partial drain, as if the process crashed after one event.
    $partial = $reader->drain(1);

    expect($partial)->toBe(['processed' => 1, 'projected' => 1, 'failed' => 0])
        ->and(ShareCohortProjection::query()->count())->toBe(1);

    $resumed = $reader->drain(25);

    expect($resumed)->toBe(['processed' => 1, 'projected' => 1, 'failed' => 0])
        ->and(ShareCohortProjection::query()->count())->toBe(2);

    $stages = ShareCohortProjection::query()->pluck('stage', 'business_key')->all();

    // The link carrying the accepted outcome is participated; the bare
    // share stays observed. Nothing double-applied.
    expect($stages)->toHaveCount(2)
        ->and(collect($stages)->contains(ShareCohortProjection::STAGE_PARTICIPATED))->toBeTrue()
        ->and(collect($stages)->contains(ShareCohortProjection::STAGE_OBSERVED))->toBeTrue();

    $cursor = ShareSignalReaderCursor::query()->where('reader_name', CommittedShareSignalReader::READER_NAME)->firstOrFail();

    expect((int) $cursor->processed_total)->toBe(2);

    // A quiet drain is a verified no-op.
    expect($reader->drain(25))->toBe(['processed' => 0, 'projected' => 0, 'failed' => 0]);

    // Operator replay re-reads the retained log without duplicating.
    $reader->resetCursor();

    expect($reader->drain(25))->toBe(['processed' => 2, 'projected' => 2, 'failed' => 0])
        ->and(ShareCohortProjection::query()->count())->toBe(2);
});

it('skips poison signals loudly without stalling the pipeline', function (): void {
    $touchpoint = cssShare($this, $this->sharer, CarbonImmutable::parse('2026-05-10T08:00:00Z'));

    $property = TrackedProperty::query()->where('slug', 'ilmu360')->firstOrFail();

    app(SignalEventIngestor::class)->handle($property, [
        'event_name' => CommittedShareSignalProducer::EVENT_NAME,
        'event_category' => CommittedShareSignalProducer::EVENT_CATEGORY,
        'source_event_id' => 'garbage-without-prefix',
        'idempotency_key' => 'garbage-without-prefix',
        'occurred_at' => CarbonImmutable::parse('2026-05-11T08:00:00Z')->toIso8601String(),
        'properties' => [],
    ], true);

    app(SignalEventIngestor::class)->handle($property, [
        'event_name' => CommittedShareSignalProducer::EVENT_NAME,
        'event_category' => CommittedShareSignalProducer::EVENT_CATEGORY,
        'source_event_id' => 'committed-share:'.((string) Str::uuid()),
        'idempotency_key' => 'committed-share:'.((string) Str::uuid()),
        'occurred_at' => CarbonImmutable::parse('2026-05-12T08:00:00Z')->toIso8601String(),
        'properties' => [],
    ], true);

    $result = (new CommittedShareSignalReader)->drain(25);

    expect($result)->toBe(['processed' => 3, 'projected' => 1, 'failed' => 2])
        ->and(ShareCohortProjection::query()->where('business_key', 'committed-share:'.$touchpoint->getKey())->count())->toBe(1);

    // The cursor advanced past the poison: the pipeline is not wedged,
    // and the retained log still holds the evidence for replay.
    expect((new CommittedShareSignalReader)->drain(25))->toBe(['processed' => 0, 'projected' => 0, 'failed' => 0])
        ->and(SignalEvent::query()->where('event_name', CommittedShareSignalProducer::EVENT_NAME)->count())->toBe(3);
});

it('keeps the generic dispatcher away from committed-share work, both directions', function (): void {
    cssShare($this, $this->sharer, CarbonImmutable::parse('2026-05-10T08:00:00Z'));

    // Signals live in the package log, not in effect receipts: the
    // dispatcher has nothing to steal and processes nothing.
    expect(ShareEffectReceipt::query()->count())->toBe(0)
        ->and(DispatchShareEffectReceiptsAction::run(50))->toBe(0);

    // A foreign effect row is explicitly not owned: ignored, never failed.
    $foreign = ShareEffectReceipt::query()->create([
        'effect_key' => 'mystery:css-1',
        'source_type' => 'test',
        'source_id' => 'css-1',
        'effect_type' => 'mystery',
        'status' => ShareEffectStatus::Pending,
        'payload' => ['conversion_id' => (string) Str::uuid(), 'link_id' => (string) Str::uuid()],
        'attempts' => 0,
    ]);

    expect(DispatchShareEffectReceiptsAction::run(50))->toBe(0)
        ->and($foreign->refresh()->status)->toBe(ShareEffectStatus::Pending);

    // Owned projection effects still dispatch: ownership is explicit,
    // not a blanket skip. Landing and outcome share one clock so the
    // browser selection is live when the outcome lands.
    $payload = cssPayload($this, $this->sharer, route('events.show', $this->event));
    Carbon::setTestNow(Carbon::parse('2026-05-12T08:00:00Z'));
    $cookie = cssLandingCookie($this, (string) $payload['url']);

    $actor = User::factory()->create();
    $request = Request::create('/noop', 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), $cookie);
    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventCheckin,
        ShareOutcomeBusinessKey::for(DawahShareOutcomeType::EventCheckin, 'checkin:'.((string) Str::uuid())),
        null,
        $actor,
        $request,
        [],
        CarbonImmutable::parse('2026-05-12T08:00:00Z'),
    );
    Carbon::setTestNow();

    expect(ShareEffectReceipt::query()->where('effect_type', 'projection')->where('status', ShareEffectStatus::Pending)->count())->toBe(1)
        ->and(DispatchShareEffectReceiptsAction::run(50))->toBe(1)
        ->and(ShareEffectReceipt::query()->where('effect_type', 'projection')->where('status', ShareEffectStatus::Succeeded)->count())->toBe(1)
        ->and($foreign->refresh()->status)->toBe(ShareEffectStatus::Pending);
});
