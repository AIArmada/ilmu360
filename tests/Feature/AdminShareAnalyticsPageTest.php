<?php

use AIArmada\FilamentSignals\Pages\PageViewsReport;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Filament\Pages\ProductSignals;
use App\Filament\Pages\ShareAnalytics;
use App\Models\Event;
use App\Models\User;
use App\Services\Signals\SignalsTracker;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

it('registers the signals reports inside the admin panel', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $trackedProperty = TrackedProperty::query()->firstOrFail();

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => null,
        'signal_identity_id' => null,
        'occurred_at' => now(),
        'event_name' => 'page_view',
        'event_category' => 'page_view',
        'path' => '/dashboard',
        'url' => url('/dashboard'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => null,
        'property_types' => null,
    ]);

    $this->actingAs($administrator)
        ->get(PageViewsReport::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Page Views');
});

it('renders the admin share analytics page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $this->actingAs($administrator)
        ->get(ShareAnalytics::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Share Analytics')
        ->assertSee('Provider Breakdown')
        ->assertSee('Top Sharers')
        ->assertSee('Top Links');
});

it('renders product signals client origin and platform visibility in admin', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $trackedProperty = TrackedProperty::query()->firstOrFail();

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => null,
        'signal_identity_id' => null,
        'occurred_at' => now(),
        'event_name' => 'search.executed',
        'event_category' => 'search',
        'path' => '/api/v1/events',
        'url' => url('/api/v1/events'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => [
            'client_origin' => 'ios',
            'client_platform' => 'ios',
            'client_family' => 'mobile',
            'client_transport' => 'api',
            'client_version' => '1.2.3',
            'query' => 'Admin visible mobile search',
        ],
        'property_types' => null,
    ]);

    SignalEvent::query()->create([
        'tracked_property_id' => $trackedProperty->id,
        'signal_session_id' => null,
        'signal_identity_id' => null,
        'occurred_at' => now()->subMinute(),
        'event_name' => 'navigation.quick_filter_clicked',
        'event_category' => 'navigation',
        'path' => '/',
        'url' => url('/'),
        'currency' => 'MYR',
        'revenue_minor' => 0,
        'properties' => [
            'client_origin' => 'web',
            'client_platform' => 'macos',
            'client_family' => 'desktop',
            'client_transport' => 'web',
        ],
        'property_types' => null,
    ]);

    $this->actingAs($administrator)
        ->get(ProductSignals::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Product Signals')
        ->assertSee('Client Origins')
        ->assertSee('Platforms')
        ->assertSee('Transport')
        ->assertSee('iOS')
        ->assertSee('macOS')
        ->assertSee('API')
        ->assertSee('Admin visible mobile search')
        ->assertSee('1.2.3');
});

it('does not expose the product scorecard to unauthorised users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(ProductSignals::getUrl(panel: 'admin'))
        ->assertForbidden();
});

it('shows copy link activity on the admin share analytics page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $sharer = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);

    $trackingToken = $this->actingAs($sharer)
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.show', $event),
            'text' => 'Share this event',
            'title' => $event->title,
        ]))
        ->assertOk()
        ->json('tracking_token');

    $this->actingAs($sharer)
        ->postJson(route('dawah-share.track'), [
            'provider' => 'copy_link',
            'tracking_token' => $trackingToken,
        ])
        ->assertNoContent();

    $this->actingAs($administrator)
        ->get(ShareAnalytics::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Copy Link');
});

it('shows threads activity on the admin share analytics page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $sharer = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);

    $trackingToken = $this->actingAs($sharer)
        ->getJson(route('dawah-share.payload', [
            'url' => route('events.show', $event),
            'text' => 'Share this event',
            'title' => $event->title,
        ]))
        ->assertOk()
        ->json('tracking_token');

    $this->actingAs($sharer)
        ->postJson(route('dawah-share.track'), [
            'provider' => 'threads',
            'tracking_token' => $trackingToken,
        ])
        ->assertNoContent();

    $this->actingAs($administrator)
        ->get(ShareAnalytics::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Threads');
});

it('injects a dedicated admin tracker by default', function () {
    $publicProperty = TrackedProperty::query()->firstOrFail();
    $adminProperty = TrackedProperty::query()->where('slug', 'ilmu360-admin')->firstOrFail();
    $trackerConfig = app(SignalsTracker::class)->trackerConfig('admin');

    expect($trackerConfig)->not->toBeNull();
    expect(data_get($trackerConfig, 'write_key'))->toBe((string) $adminProperty->write_key);
    expect(data_get($trackerConfig, 'write_key'))->not->toBe((string) $publicProperty->write_key);
});

it('can still disable the admin tracker surface explicitly', function () {
    config()->set('product-signals.panels.admin.enabled', false);

    $trackerConfig = app(SignalsTracker::class)->trackerConfig('admin');

    expect($trackerConfig)->toBeNull();
});

it('resolves a dedicated tracker for the ahli panel', function () {
    $publicProperty = TrackedProperty::query()->where('slug', 'ilmu360')->firstOrFail();
    $ahliProperty = app(SignalsTracker::class)->trackedPropertyForSurface('ahli');
    $trackerConfig = app(SignalsTracker::class)->trackerConfig('ahli');

    expect($ahliProperty)->not->toBeNull();
    expect($ahliProperty?->slug)->toBe('ilmu360-ahli');
    expect($trackerConfig)->not->toBeNull();
    expect(data_get($trackerConfig, 'write_key'))->toBe((string) $ahliProperty?->write_key);
    expect(data_get($trackerConfig, 'write_key'))->not->toBe((string) $publicProperty->write_key);
});

it('can resolve a future panel surface without hardcoded support', function () {
    config()->set('product-signals.panels.partner.enabled', true);
    config()->set('filament-panels.domains.partner', 'partner.ilmu360.test');

    $partnerProperty = app(SignalsTracker::class)->trackedPropertyForSurface('partner');
    $trackerConfig = app(SignalsTracker::class)->trackerConfig('partner');

    expect($partnerProperty)->not->toBeNull();
    expect($partnerProperty?->slug)->toBe('ilmu360-partner');
    expect($partnerProperty?->domain)->toBe('partner.ilmu360.test');
    expect($trackerConfig)->not->toBeNull();
    expect(data_get($trackerConfig, 'write_key'))->toBe((string) $partnerProperty?->write_key);
});
