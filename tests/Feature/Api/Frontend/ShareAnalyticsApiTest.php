<?php

use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns authenticated share analytics dashboard and link detail data for mobile clients', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subDay(),
        'is_active' => true,
    ]);

    $payload = $this->getJson(route('api.client.share.payload', [
        'url' => route('events.show', $event),
        'text' => 'Join this majlis',
        'title' => $event->title,
        'origin' => 'iosapp',
    ]))
        ->assertOk()
        ->json();

    $trackingToken = (string) data_get($payload, 'tracking_token');
    $shareUrl = (string) data_get($payload, 'url');

    $this->postJson(route('api.client.share.track'), [
        'provider' => 'copy_link',
        'tracking_token' => $trackingToken,
    ])->assertNoContent();

    $this->get($shareUrl)->assertOk();

    $dashboard = $this->getJson(route('api.client.share.analytics', [
        'type' => 'event',
        'sort' => 'visits',
    ]))
        ->assertOk()
        ->json();

    expect(data_get($dashboard, 'meta.filters.type'))->toBe('event')
        ->and(data_get($dashboard, 'meta.filters.sort'))->toBe('visits')
        ->and(data_get($dashboard, 'data.summary.outbound_shares'))->toBe(1)
        ->and(data_get($dashboard, 'data.summary.visits'))->toBe(1)
        ->and(data_get($dashboard, 'data.links.meta.pagination.total'))->toBe(1)
        ->and(data_get($dashboard, 'data.links.data.0.subject_type'))->toBe('event');

    $linkId = (string) data_get($dashboard, 'data.links.data.0.id');

    $detail = $this->getJson(route('api.client.share.analytics.links.show', [
        'link' => $linkId,
    ]))
        ->assertOk()
        ->json();

    expect(data_get($detail, 'data.link.id'))->toBe($linkId)
        ->and(data_get($detail, 'data.provider_breakdown'))->toBeArray()
        ->and(collect(data_get($detail, 'data.provider_breakdown', []))->pluck('provider')->all())->toContain('copy_link')
        ->and(data_get($detail, 'data.share_links'))->toBeArray()
        ->and((string) data_get($detail, 'data.share_links.whatsapp'))->toContain('whatsapp')
        ->and(data_get($detail, 'data.daily_performance'))->toBeArray()
        ->and(data_get($detail, 'data.recent_visits'))->toBeArray()
        ->and(data_get($detail, 'data.recent_outcomes'))->toBeArray()
        ->and(data_get($detail, 'data.activity_window.latest_activity_at'))->not->toBeNull();
});

it('requires authentication for share analytics endpoints', function () {
    $this->getJson(route('api.client.share.analytics'))->assertUnauthorized();

    $this->getJson(route('api.client.share.analytics.links.show', [
        'link' => 'missing-link',
    ]))->assertUnauthorized();
});

it('allows guest mobile clients to record share tracking through the api', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now()->subDay(),
        'is_active' => true,
    ]);

    $payload = $this->getJson(route('api.client.share.payload', [
        'url' => route('events.show', $event),
        'text' => 'Join this majlis',
        'title' => $event->title,
        'origin' => 'android',
    ]))
        ->assertOk()
        ->json();

    $this->postJson(route('api.client.share.track'), [
        'provider' => 'native_share',
        'tracking_token' => (string) data_get($payload, 'tracking_token'),
    ])->assertNoContent();
});

it('rejects invalid analytics filters and malformed link identifiers', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.client.share.analytics', [
        'type' => 'not-a-real-type',
        'sort' => 'not-a-real-sort',
        'status' => 'not-a-real-status',
        'outcome' => 'not-a-real-outcome',
        'page' => 0,
        'per_page' => 0,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'type',
            'sort',
            'status',
            'outcome',
            'page',
            'per_page',
        ]);

    $this->getJson(route('api.client.share.analytics.links.show', [
        'link' => 'not-a-uuid',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['link']);
});
