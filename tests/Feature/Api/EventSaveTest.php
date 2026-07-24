<?php

use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\EngagementCounter;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
    ]);
});

test('authenticated user can save an event', function () {
    Sanctum::actingAs($this->user);

    $response = $this->putJson(route('api.events.saved.update', $this->event));

    $response->assertStatus(201)
        ->assertJsonPath('message', 'Event saved successfully.')
        ->assertJsonPath('data.is_saved', true)
        ->assertJsonPath('data.saves_count', 1)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    $this->assertDatabaseHas('engagement_bookmarks', [
        'bookmarker_type' => $this->user->getMorphClass(),
        'bookmarker_id' => $this->user->id,
        'bookmarkable_type' => $this->event->getMorphClass(),
        'bookmarkable_id' => $this->event->id,
        'status' => 'active',
    ]);
});

test('unauthenticated user cannot save an event', function () {
    $response = $this->putJson(route('api.events.saved.update', $this->event));

    $response->assertStatus(401);
});

test('saving the same event twice is idempotent', function () {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.events.saved.update', $this->event));

    $response = $this->putJson(route('api.events.saved.update', $this->event));

    $response->assertOk()
        ->assertJsonPath('message', 'Event already saved.')
        ->assertJsonPath('data.is_saved', true)
        ->assertJsonPath('data.saves_count', 1);
});

test('authenticated user can save a pending public event', function () {
    Sanctum::actingAs($this->user);

    $event = Event::factory()->create([
        'status' => 'pending',
        'visibility' => 'public',
    ]);

    $this->putJson(route('api.events.saved.update', $event))
        ->assertCreated()
        ->assertJsonPath('data.is_saved', true)
        ->assertJsonPath('data.saves_count', 1);
});

test('non-public events cannot be saved through the api', function () {
    Sanctum::actingAs($this->user);

    $event = Event::factory()->create([
        'status' => 'draft',
        'visibility' => 'private',
    ]);

    $this->putJson(route('api.events.saved.update', $event))
        ->assertForbidden()
        ->assertJsonPath('error.message', 'This event cannot be saved.');
});

test('authenticated user can unsave an event', function () {
    Sanctum::actingAs($this->user);

    $this->putJson(route('api.events.saved.update', $this->event));

    $response = $this->deleteJson(route('api.events.saved.destroy', $this->event));

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Event save removed successfully.')
        ->assertJsonPath('data.is_saved', false)
        ->assertJsonPath('data.saves_count', 0)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    $this->assertDatabaseMissing('engagement_bookmarks', [
        'bookmarker_type' => $this->user->getMorphClass(),
        'bookmarker_id' => $this->user->id,
        'bookmarkable_type' => $this->event->getMorphClass(),
        'bookmarkable_id' => $this->event->id,
        'status' => 'active',
    ]);
});

test('removing a save is idempotent even when it was not set', function () {
    Sanctum::actingAs($this->user);

    $response = $this->deleteJson(route('api.events.saved.destroy', $this->event));

    $response->assertOk()
        ->assertJsonPath('message', 'Event was not saved.')
        ->assertJsonPath('data.is_saved', false)
        ->assertJsonPath('data.saves_count', 0);
});

test('event me shows whether the event is saved', function () {
    Sanctum::actingAs($this->user);

    $this->getJson(route('api.events.me.show', $this->event))
        ->assertOk()
        ->assertJsonPath('data.saved.is_saved', false)
        ->assertJsonPath('data.saved.saves_count', 0)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    $this->putJson(route('api.events.saved.update', $this->event));

    $this->getJson(route('api.events.me.show', $this->event))
        ->assertOk()
        ->assertJsonPath('data.saved.is_saved', true)
        ->assertJsonPath('data.saved.saves_count', 1)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
});

test('saving an event recalculates stale saves_count from source rows', function () {
    Sanctum::actingAs($this->user);

    EngagementCounter::updateOrCreate([
        'subject_type' => $this->event->getMorphClass(),
        'subject_id' => $this->event->getKey(),
        'counter_type' => 'bookmarks',
        'counter_key' => '',
    ], ['count_value' => 99]);

    $response = $this->putJson(route('api.events.saved.update', $this->event));

    $response->assertStatus(201);

    expect(withGlobalOwnerContext(fn () => EngagementCounter::where('subject_type', $this->event->getMorphClass())
        ->where('subject_id', $this->event->getKey())
        ->where('counter_type', 'bookmarks')
        ->where('counter_key', '')
        ->value('count_value') ?? 0))->toBe(1);
});

test('saved events index still includes cancelled events', function () {
    Sanctum::actingAs($this->user);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Saved',
        'slug' => 'masjid-saved',
    ]);
    $venue = Venue::factory()->create([
        'name' => 'Dewan Saved',
    ]);
    $person = Person::factory()->create([
        'name' => 'Person Saved',
        'slug' => 'speaker-saved',
    ]);

    $savedEvent = Event::factory()->create([
        'title' => 'Saved Event One',
        'slug' => 'saved-event-one',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(5),
        'event_url' => 'https://example.com/events/saved-event-one',
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
    ]);

    $cancelledEvent = Event::factory()->create([
        'status' => 'cancelled',
        'visibility' => 'public',
        'starts_at' => now()->addDays(10),
    ]);
    $inactiveEvent = Event::factory()->create([
        'status' => 'draft',
        'visibility' => 'private',
        'starts_at' => now()->addDays(12),
    ]);

    $savedEvent->speakers()->attach($person->id);

    app(EngagementManager::class)->bookmark($this->user, $savedEvent);
    app(EngagementManager::class)->bookmark($this->user, $cancelledEvent);
    app(EngagementManager::class)->bookmark($this->user, $inactiveEvent);

    $response = $this->getJson(route('api.events.saved.index'));

    $response->assertOk()
        ->assertJsonPath('meta.pagination.has_more', false)
        ->assertJsonPath('data.0.id', $savedEvent->id)
        ->assertJsonPath('data.0.title', 'Saved Event One')
        ->assertJsonPath('data.0.slug', 'saved-event-one')
        ->assertJsonPath('data.0.status', 'approved')
        ->assertJsonPath('data.0.visibility', 'public')
        ->assertJsonPath('data.0.event_url', 'https://example.com/events/saved-event-one')
        ->assertJsonPath('data.0.institution.id', $institution->id)
        ->assertJsonPath('data.0.institution.name', 'Masjid Saved')
        ->assertJsonPath('data.0.institution.slug', $institution->slug)
        ->assertJsonPath('data.0.venue.id', $venue->id)
        ->assertJsonPath('data.0.venue.name', 'Dewan Saved')
        ->assertJsonPath('data.0.speakers.0.id', $person->id)
        ->assertJsonPath('data.0.speakers.0.name', 'Person Saved')
        ->assertJsonPath('data.0.speakers.0.slug', $person->slug)
        ->assertJsonMissing(['id' => $inactiveEvent->id])
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
});

test('saved events index clamps per_page values to the supported maximum', function () {
    Sanctum::actingAs($this->user);

    Event::factory()->count(105)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ])->each(function (Event $event): void {
        app(EngagementManager::class)->bookmark($this->user, $event);
    });

    $this->getJson(route('api.events.saved.index', ['per_page' => 500]))
        ->assertOk()
        ->assertJsonPath('meta.pagination.per_page', 100)
        ->assertJsonPath('meta.pagination.has_more', true)
        ->assertJsonCount(100, 'data');
});

test('saved events index keeps missing institution and venue relations as null', function () {
    Sanctum::actingAs($this->user);

    $event = Event::factory()->create([
        'title' => 'Saved Event Without Relations',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
        'institution_id' => null,
        'default_venue_id' => null,
    ]);

    app(EngagementManager::class)->bookmark($this->user, $event);

    $this->getJson(route('api.events.saved.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $event->id)
        ->assertJsonPath('data.0.institution', null)
        ->assertJsonPath('data.0.venue', null);
});
