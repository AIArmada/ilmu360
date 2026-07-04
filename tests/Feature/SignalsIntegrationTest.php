<?php

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\SignalsServiceProvider;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Services\Signals\AffiliateSignalsBridge;
use App\Services\Signals\SignalsTracker;
use Illuminate\Http\Request;
use Mockery\MockInterface;

it('registers the signals package migrations with the application', function () {
    $provider = new SignalsServiceProvider(app());
    $provider->register();

    $reflection = new ReflectionProperty($provider, 'package');

    $package = $reflection->getValue($provider);

    expect($package->runsMigrations)->toBeTrue()
        ->and($package->discoversMigrations)->toBeTrue();
});

it('injects the signals tracker script into public layouts when a tracked property exists', function () {
    $trackedProperty = TrackedProperty::query()->first();

    expect($trackedProperty)->not->toBeNull();
    expect(app('router')->has('signals.tracker.script'))->toBeTrue();

    $trackerConfig = app(SignalsTracker::class)->trackerConfig('public');

    expect($trackerConfig)->not->toBeNull();
    expect(data_get($trackerConfig, 'write_key'))->toBe((string) $trackedProperty?->write_key);
    expect(data_get($trackerConfig, 'script_url'))->toContain('/api/signals/tracker.js');
    expect(data_get($trackerConfig, 'event_endpoint'))->toContain('/api/signals/collect/event');
    expect(data_get($trackerConfig, 'identify_endpoint'))->toContain('/api/signals/collect/identify');
    expect(data_get($trackerConfig, 'anonymous_cookie_name'))->toBe('mi_signals_anonymous_id');
    expect(data_get($trackerConfig, 'session_cookie_name'))->toBe('mi_signals_session_id');
});

it('renders the centralized custom UI event tracker and discovery funnel hooks', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('window.ilmu360.trackSignal', false)
        ->assertSee('/api/signals/collect/event', false)
        ->assertSee("client_origin: 'web'", false)
        ->assertSee("client_transport: 'web'", false)
        ->assertSee('data-signal-submit-event="search.submitted"', false)
        ->assertSee('data-signal-event="search.nearby_requested"', false)
        ->assertSee('data-signal-event="navigation.quick_filter_clicked"', false)
        ->assertSee('data-signal-event="submission.event_start_clicked"', false);

    $this->get(route('events.index'))
        ->assertSuccessful()
        ->assertSee('data-signal-change-event="filter.changed"', false)
        ->assertSee('data-signal-change-event="filter.sort_changed"', false)
        ->assertSee('data-signal-event="search.nearby_requested"', false);
});

it('renders event detail conversion tracking hooks', function () {
    $event = Event::factory()->create([
        'event_format' => 'online',
        'institution_id' => null,
        'live_url' => 'https://example.test/live',
        'starts_at' => now()->addWeek(),
        'status' => 'approved',
        'visibility' => 'public',
    ]);

    $this->get(route('events.show', $event))
        ->assertSuccessful()
        ->assertSee('data-signal-event="engagement.event_going_clicked"', false)
        ->assertSee('data-signal-event="engagement.event_save_clicked"', false)
        ->assertSee('data-signal-event="engagement.event_check_in_clicked"', false)
        ->assertSee('data-signal-event="engagement.calendar_opened"', false)
        ->assertSee('data-signal-event="share.modal_opened"', false)
        ->assertSee('data-signal-event="share.provider_clicked"', false)
        ->assertSee('data-signal-event="navigation.external_link_clicked"', false);
});

it('accepts signals page view ingestion for the default tracked property', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();
    expect(app('router')->has('signals.collect.pageview'))->toBeTrue();

    $this->postJson('/api/signals/collect/pageview', [
        'write_key' => $trackedProperty->write_key,
        'session_identifier' => 'session-test-1',
        'path' => '/majlis',
        'url' => url('/majlis'),
        'title' => 'Majlis',
    ])->assertAccepted();

    expect(SignalEvent::query()
        ->where('tracked_property_id', $trackedProperty->id)
        ->where('event_name', 'page_view')
        ->count())->toBe(1);
});

it('records signals events when affiliate attribution and attributed outcomes occur', function () {
    $sharer = User::factory()->create();
    $visitor = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);
    $shareService = app(ShareTrackingService::class);
    $sharedUrl = $shareService->attributedUrl($sharer, route('events.show', $event), $event->title);

    $landingResponse = $this->get($sharedUrl);
    $cookie = $landingResponse->getCookie(config('dawah-share.cookie.name'));

    expect($cookie)->not->toBeNull();

    $request = Request::create(route('events.show', $event), 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), (string) $cookie?->getValue());
    $request->setUserResolver(fn (): User => $visitor);

    $shareService->recordOutcome(
        DawahShareOutcomeType::EventSave,
        'signals-test:event-save:'.$visitor->id.':'.$event->id,
        $event,
        $visitor,
        $request,
    );

    expect(SignalEvent::query()->where('event_name', 'affiliate.attributed')->exists())->toBeTrue();
    expect(SignalEvent::query()->where('event_name', 'affiliate.conversion.recorded')->exists())->toBeTrue();
    expect(SignalEvent::query()
        ->withoutOwnerScope()
        ->whereIn('event_name', ['affiliate.attributed', 'affiliate.conversion.recorded'])
        ->whereNotNull('owner_id')
        ->exists())->toBeFalse();
});

it('does not break affiliate-backed outcomes when signals ingestion fails', function () {
    $this->mock(AffiliateSignalsBridge::class, function (MockInterface $mock): void {
        $mock->shouldReceive('recordAffiliateAttributed')->andThrow(new RuntimeException('Signals affiliate attribution failed.'));
        $mock->shouldReceive('recordAffiliateConversionRecorded')->andThrow(new RuntimeException('Signals affiliate conversion failed.'));
    });

    $sharer = User::factory()->create();
    $visitor = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);
    $shareService = app(ShareTrackingService::class);
    $sharedUrl = $shareService->attributedUrl($sharer, route('events.show', $event), $event->title);

    $landingResponse = $this->get($sharedUrl);
    $cookie = $landingResponse->getCookie(config('dawah-share.cookie.name'));

    expect($cookie)->not->toBeNull();

    $request = Request::create(route('events.show', $event), 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), (string) $cookie?->getValue());
    $request->setUserResolver(fn (): User => $visitor);

    $outcome = $shareService->recordOutcome(
        DawahShareOutcomeType::EventSave,
        'signals-test-safe:event-save:'.$visitor->id.':'.$event->id,
        $event,
        $visitor,
        $request,
    );

    expect($outcome)->not->toBeNull();
    expect($outcome?->outcomeType)->toBe(DawahShareOutcomeType::EventSave->value);
});
