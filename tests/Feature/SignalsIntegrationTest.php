<?php

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\SignalIdentity;
use AIArmada\Signals\Models\SignalSession;
use AIArmada\Signals\Models\TrackedProperty;
use AIArmada\Signals\SignalsServiceProvider;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Services\Signals\AffiliateSignalsBridge;
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

it('resolves the default tracked property', function () {
    $trackedProperty = TrackedProperty::query()->first();

    expect($trackedProperty)->not->toBeNull();
    expect(app('router')->has('signals.tracker.script'))->toBeTrue();

    $default = TrackedProperty::query()
        ->withoutOwnerScope()
        ->whereNull('owner_type')
        ->whereNull('owner_id')
        ->where('slug', (string) config('signals.integrations.browser.tracked_property.slug', 'ilmu360'))
        ->first();

    expect($default)->not->toBeNull();
    expect($default?->write_key)->toBe((string) $trackedProperty?->write_key);
});

it('renders the inline signal event hooks used by the package tracker', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('data-signals-tracker', false)
        ->assertSee('/api/v1/signals/collect/browser-event', false)
        ->assertSee('data-signal-submit-event="search.submitted"', false)
        ->assertSee('data-signal-event="search.nearby_requested"', false)
        ->assertSee('data-signal-event="filter.quick_selected"', false)
        ->assertSee('data-signal-event="submission.event_start_clicked"', false);

    $this->get(route('events.index'))
        ->assertSuccessful()
        ->assertSee('data-signal-change-event="filter.changed"', false)
        ->assertSee('data-signal-change-event="filter.sort_changed"', false)
        ->assertSee('data-signal-event="search.nearby_requested"', false);
});

it('renders event detail conversion tracking hooks', function () {
    $event = Event::factory()->create([
        'delivery_mode' => 'online',
        'institution_id' => null,
        'live_url' => 'https://example.test/live',
        'starts_at' => now()->addWeek(),
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);

    // The check-in hook only renders for events with registration, which
    // the factory draws for 30% of events. Pin it so this test is not a
    // lottery ticket.
    $event->forceFill(['registration_mode' => RegistrationMode::Required->value])->save();
    $event->accessPolicy()->create([
        'registration_required' => true,
        'capacity' => 100,
        'walk_in_allowed' => false,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDays(6),
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

    $this->postJson('/api/v1/signals/collect/pageview', [
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

it('accepts signals identity ingestion for the default tracked property', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();

    $this->postJson('/api/v1/signals/collect/identify', [
        'write_key' => $trackedProperty->write_key,
        'external_id' => 'user-ext-1',
        'email' => 'signals-identify@example.test',
    ], ['Origin' => url('/')])->assertAccepted()
        ->assertJsonPath('status', 'ok');

    expect(SignalIdentity::query()
        ->where('tracked_property_id', $trackedProperty->id)
        ->where('external_id', 'user-ext-1')
        ->exists())->toBeTrue();
});

it('captures signals geolocation for a known session', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();

    $this->postJson('/api/v1/signals/collect/pageview', [
        'write_key' => $trackedProperty->write_key,
        'session_identifier' => 'session-geo-1',
        'path' => '/majlis',
        'url' => url('/majlis'),
        'title' => 'Majlis',
    ])->assertAccepted();

    $this->postJson('/api/v1/signals/collect/geo', [
        'write_key' => $trackedProperty->write_key,
        'session_identifier' => 'session-geo-1',
        'latitude' => 3.139,
        'longitude' => 101.6869,
        'accuracy' => 25,
    ], ['Origin' => url('/')])->assertAccepted()
        ->assertJsonPath('status', 'ok');

    $session = SignalSession::query()->where('session_identifier', 'session-geo-1')->firstOrFail();

    expect((float) $session->latitude)->toEqual(3.139)
        ->and((float) $session->longitude)->toEqual(101.6869)
        ->and($session->geolocation_source)->toBe('browser');
});

it('accepts signed trusted server outcomes', function () {
    $trackedProperty = TrackedProperty::query()->firstOrFail();
    config()->set('signals.ingestion.trusted.secret', 'test-trusted-secret');

    $payload = [
        'write_key' => $trackedProperty->write_key,
        'event_name' => 'purchase.completed',
        'event_category' => 'conversion',
        'idempotency_key' => 'test-outcome-1',
        'transaction_id' => 'txn-1',
        'revenue_minor' => 1999,
        'currency' => 'MYR',
    ];
    $content = (string) json_encode($payload);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$content, 'test-trusted-secret');

    $response = $this->call('POST', '/api/v1/signals/collect/server-outcome', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X-Signals-Timestamp' => $timestamp,
        'HTTP_X-Signals-Signature' => 'sha256='.$signature,
    ], $content);

    $response->assertAccepted();

    expect(SignalEvent::query()
        ->where('event_name', 'purchase.completed')
        ->where('tracked_property_id', $trackedProperty->id)
        ->exists())->toBeTrue();
});
