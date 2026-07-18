<?php

use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Response;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function registrationReadyEvent(array $eventOverrides = []): Event
{
    return Event::factory()
        ->create(array_merge([
            'status' => 'approved',
            'visibility' => EventVisibility::Public,
            'published_at' => now(),
        ], $eventOverrides));
}

it('allows an authenticated user to register through the api', function () {
    $user = User::factory()->create();
    $event = registrationReadyEvent();

    Sanctum::actingAs($user);

    $response = $this->postJson(route('api.events.registrations.store', $event), [
        'name' => 'Registered Mobile User',
    ]);

    $registration = Registration::query()
        ->where('event_id', $event->id)
        ->forUser($user)
        ->latest('created_at')
        ->firstOrFail();

    $response->assertCreated()
        ->assertJsonPath('data.id', $registration->id)
        ->assertJsonPath('data.event_id', $event->id)
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.name', 'Registered Mobile User')
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.created_at', $registration->created_at?->toIso8601String())
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    $this->getJson(route('api.events.me.show', $event))
        ->assertOk()
        ->assertJsonPath('data.registration.is_registered', true)
        ->assertJsonPath('data.registration.registration.id', $registration->id)
        ->assertJsonPath('data.registration.registration.event_id', $event->id)
        ->assertJsonPath('data.registration.registration.user_id', $user->id)
        ->assertJsonPath('data.registration.registration.name', 'Registered Mobile User')
        ->assertJsonPath('data.registration.registration.created_at', $registration->created_at?->toIso8601String())
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
});

it('allows a guest to register through the api when contact info is provided', function () {
    $event = registrationReadyEvent();

    $response = $this->postJson(route('api.events.registrations.store', $event), [
        'name' => 'Guest Registrant',
        'email' => 'guest@example.test',
    ]);

    $registration = Registration::query()
        ->where('event_id', $event->id)
        ->forPrimaryContact('guest@example.test')
        ->latest('created_at')
        ->firstOrFail();

    $response->assertCreated()
        ->assertJsonPath('data.id', $registration->id)
        ->assertJsonPath('data.name', 'Guest Registrant')
        ->assertJsonPath('data.email', 'guest@example.test')
        ->assertJsonPath('data.phone', null)
        ->assertJsonPath('data.user_id', null)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.created_at', $registration->created_at?->toIso8601String())
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
});

it('rejects guest registration without email or phone', function () {
    $event = registrationReadyEvent();

    $this->postJson(route('api.events.registrations.store', $event), [
        'name' => 'Guest Without Contact',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['contact']);
});

it('allows registration for unlisted events when registration is enabled', function () {
    $event = registrationReadyEvent([
        'visibility' => EventVisibility::Unlisted,
    ]);

    $this->postJson(route('api.events.registrations.store', $event), [
        'name' => 'Unlisted Registrant',
        'email' => 'unlisted@example.test',
    ])->assertCreated()
        ->assertJsonPath('data.event_id', $event->id);
});

it('rejects registration for private and draft events', function () {
    foreach ([
        ['visibility' => EventVisibility::Private, 'status' => 'approved', 'published_at' => now()],
        ['visibility' => EventVisibility::Public, 'status' => 'draft', 'published_at' => null],
    ] as $attributes) {
        $event = Event::factory()->create($attributes);

        $this->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Blocked Registrant',
            'email' => 'blocked@example.test',
        ])->assertNotFound();
    }
});

it('returns current user event state for approved unlisted events', function () {
    $user = User::factory()->create();
    $event = registrationReadyEvent([
        'visibility' => EventVisibility::Unlisted,
    ]);

    Sanctum::actingAs($user);

    $registrationResponse = $this->postJson(route('api.events.registrations.store', $event), [
        'name' => 'Unlisted State User',
    ]);

    $registrationId = (string) $registrationResponse->json('data.id');

    $this->getJson(route('api.events.me.show', $event))
        ->assertOk()
        ->assertJsonPath('data.registration.is_registered', true)
        ->assertJsonPath('data.registration.registration.id', $registrationId)
        ->assertJsonPath('data.registration.registration.event_id', $event->id)
        ->assertJsonPath('data.registration.registration.user_id', $user->id)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
});

it('returns stored engagement counts for the current user event state', function () {
    $user = User::factory()->create();
    $event = registrationReadyEvent();

    // Create 12 bookmarks (saves) by different users
    $bookmarkerIds = User::factory(12)->create()->pluck('id');
    foreach ($bookmarkerIds as $bid) {
        Bookmark::factory()->create([
            'bookmarker_type' => (new User)->getMorphClass(),
            'bookmarker_id' => $bid,
            'bookmarkable_type' => $event->getMorphClass(),
            'bookmarkable_id' => $event->getKey(),
        ]);
    }

    // Create 34 going responses by different users
    $responderIds = User::factory(34)->create()->pluck('id');
    foreach ($responderIds as $rid) {
        Response::factory()->create([
            'responder_type' => (new User)->getMorphClass(),
            'responder_id' => $rid,
            'respondable_type' => $event->getMorphClass(),
            'respondable_id' => $event->getKey(),
            'response_type' => 'going',
        ]);
    }

    Sanctum::actingAs($user);

    $this->getJson(route('api.events.me.show', $event))
        ->assertOk()
        ->assertJsonPath('data.saved.is_saved', false)
        ->assertJsonPath('data.saved.saves_count', 12)
        ->assertJsonPath('data.going.is_going', false)
        ->assertJsonPath('data.going.going_count', 34)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
});

it('requires authentication to inspect the current users event state', function () {
    $event = registrationReadyEvent();

    $this->getJson(route('api.events.me.show', $event))
        ->assertUnauthorized();
});
