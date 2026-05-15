<?php

use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns check-in state and records a self-reported check-in for open events', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now('Asia/Kuala_Lumpur')->addHour()->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $event->settings()->delete();

    Sanctum::actingAs($user);

    $stateResponse = $this->getJson(route('api.events.me.show', $event));

    $stateResponse
        ->assertOk()
        ->assertJsonPath('data.check_in.is_checked_in', false)
        ->assertJsonPath('data.check_in.available', true)
        ->assertJsonPath('data.check_in.reason', null)
        ->assertJsonPath('data.check_in.method', 'self_reported')
        ->assertJsonPath('data.check_in.registration_id', null)
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    $storeResponse = $this->postJson(route('api.events.check-ins.store', $event));

    $storeResponse
        ->assertCreated()
        ->assertJsonPath('message', 'Check-in recorded successfully.')
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.checkin.event_id', $event->id)
        ->assertJsonPath('data.checkin.user_id', $user->id)
        ->assertJsonPath('data.checkin.registration_id', null)
        ->assertJsonPath('data.checkin.method', 'self_reported')
        ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));

    $checkin = EventCheckin::query()
        ->where('event_id', $event->id)
        ->where('user_id', $user->id)
        ->where('method', 'self_reported')
        ->firstOrFail();

    $storeResponse->assertJsonPath('data.checkin.id', $checkin->id)
        ->assertJsonPath('data.checkin.checked_in_at', $checkin->checked_in_at?->format(DateTimeInterface::ATOM));

    expect($checkin->registration_id)->toBeNull();
});

it('requires registration before check-in when the event requires registration', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now('Asia/Kuala_Lumpur')->addHour()->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $event->settings()->updateOrCreate([], [
        'registration_required' => true,
        'registration_mode' => 'event',
        'registration_opens_at' => now()->subDay(),
        'registration_closes_at' => now()->addDay(),
    ]);

    Sanctum::actingAs($user);

    $this->getJson(route('api.events.me.show', $event))
        ->assertOk()
        ->assertJsonPath('data.check_in.available', false)
        ->assertJsonPath('data.check_in.reason', 'Majlis ini memerlukan pendaftaran sebelum check-in.');

    $this->postJson(route('api.events.check-ins.store', $event))
        ->assertForbidden()
        ->assertJsonPath('error.message', 'Majlis ini memerlukan pendaftaran sebelum check-in.');
});

it('uses the registered check-in path when the user already has a registration', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now('Asia/Kuala_Lumpur')->addHour()->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $event->settings()->updateOrCreate([], [
        'registration_required' => true,
        'registration_mode' => 'event',
        'registration_opens_at' => now()->subDay(),
        'registration_closes_at' => now()->addDay(),
    ]);

    $registration = Registration::factory()->create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'registered',
    ]);

    Sanctum::actingAs($user);

    $this->getJson(route('api.events.me.show', $event))
        ->assertOk()
        ->assertJsonPath('data.check_in.available', true)
        ->assertJsonPath('data.check_in.method', 'registered_self_checkin')
        ->assertJsonPath('data.check_in.registration_id', $registration->id);

    $this->postJson(route('api.events.check-ins.store', $event))
        ->assertCreated()
        ->assertJsonPath('data.checkin.method', 'registered_self_checkin')
        ->assertJsonPath('data.checkin.registration_id', $registration->id);
});

it('returns a duplicate status instead of creating a second check-in', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now('Asia/Kuala_Lumpur')->addHour()->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $event->settings()->delete();

    Sanctum::actingAs($user);

    $this->postJson(route('api.events.check-ins.store', $event))
        ->assertCreated();

    $existingCheckin = EventCheckin::query()
        ->where('event_id', $event->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    $this->postJson(route('api.events.check-ins.store', $event))
        ->assertOk()
        ->assertJsonPath('message', 'You have already checked in for this event.')
        ->assertJsonPath('data.status', 'duplicate')
        ->assertJsonPath('data.checkin.id', $existingCheckin->id)
        ->assertJsonPath('data.checkin.method', 'self_reported')
        ->assertJsonPath('data.checkin.checked_in_at', $existingCheckin->checked_in_at?->format(DateTimeInterface::ATOM));

    expect(EventCheckin::query()
        ->where('event_id', $event->id)
        ->where('user_id', $user->id)
        ->count())->toBe(1);
});
