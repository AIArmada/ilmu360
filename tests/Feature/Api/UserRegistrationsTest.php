<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Registration;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('authenticated user can list own registrations', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid API',
        'slug' => 'masjid-api',
    ]);
    $venue = Venue::factory()->create([
        'name' => 'Dewan API',
    ]);

    $event = Event::factory()->create([
        'title' => 'User Registration Event',
        'slug' => 'user-registration-event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
        'institution_id' => $institution->id,
        'venue_id' => $venue->id,
    ]);

    $otherEvent = Event::factory()->create([
        'title' => 'Other Registration Event',
        'status' => 'approved',
        'visibility' => 'public',
    ]);

    $registration = Registration::factory()->for($event)->for($user)->create([
        'name' => 'Ahmad Registrant',
        'email' => 'ahmad@example.test',
        'phone' => '+60123456789',
        'status' => 'registered',
        'checkin_token' => 'checkin-token-123',
    ]);

    Registration::factory()->for($otherEvent)->for($otherUser)->create([
        'status' => 'registered',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.user.registrations.index'));

    $response->assertSuccessful()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId))
        ->assertJsonPath('data.0.id', $registration->id)
        ->assertJsonPath('data.0.event_id', $event->id)
        ->assertJsonPath('data.0.user_id', $user->id)
        ->assertJsonPath('data.0.name', 'Ahmad Registrant')
        ->assertJsonPath('data.0.email', 'ahmad@example.test')
        ->assertJsonPath('data.0.phone', '+60123456789')
        ->assertJsonPath('data.0.status', 'registered')
        ->assertJsonPath('data.0.checkin_token', 'checkin-token-123')
        ->assertJsonPath('data.0.created_at', $registration->created_at?->toIso8601String())
        ->assertJsonPath('data.0.updated_at', $registration->updated_at?->toIso8601String())
        ->assertJsonPath('data.0.event.id', $event->id)
        ->assertJsonPath('data.0.event.title', 'User Registration Event')
        ->assertJsonPath('data.0.event.slug', 'user-registration-event')
        ->assertJsonPath('data.0.event.starts_at', $event->starts_at?->toIso8601String())
        ->assertJsonPath('data.0.event.status', 'approved')
        ->assertJsonPath('data.0.event.visibility', 'public')
        ->assertJsonPath('data.0.event.institution_id', $institution->id)
        ->assertJsonPath('data.0.event.venue_id', $venue->id)
        ->assertJsonPath('data.0.event.institution.id', $institution->id)
        ->assertJsonPath('data.0.event.institution.name', 'Masjid API')
        ->assertJsonPath('data.0.event.institution.slug', $institution->slug)
        ->assertJsonPath('data.0.event.venue.id', $venue->id)
        ->assertJsonPath('data.0.event.venue.name', 'Dewan API');
});

test('unauthenticated user cannot list registrations', function () {
    $response = $this->getJson(route('api.user.registrations.index'));

    $response->assertUnauthorized();
});
