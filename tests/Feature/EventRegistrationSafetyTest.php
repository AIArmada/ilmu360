<?php

use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventSettings;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enforces capacity using live registration rows when counter is stale', function () {
    $event = Event::factory()
        ->has(EventSettings::factory()->state([
            'registration_required' => true,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
            'capacity' => 1,
        ]), 'settings')
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'registrations_count' => 0,
        ]);

    Registration::factory()->create([
        'event_id' => $event->id,
        'status' => 'registered',
        'name' => 'Existing Registrant',
        'email' => 'existing@example.com',
    ]);

    $response = $this
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'New Registrant',
            'email' => 'new@example.com',
        ]);

    $response->assertSessionHasErrors(['registration']);

    expect(Registration::query()->where('event_id', $event->id)->count())->toBe(1);
});

it('enforces uniqueness per event when registration is event-wide', function () {
    $event = Event::factory()
        ->has(EventSettings::factory()->state([
            'registration_required' => true,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
            'registration_mode' => 'event',
        ]), 'settings')
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $first = $this
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'Registrant',
            'email' => 'session-required@example.com',
        ]);

    $first->assertSessionHasNoErrors();

    $duplicate = $this
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'Registrant Again',
            'email' => 'session-required@example.com',
        ]);

    $duplicate->assertSessionHasErrors(['registration']);
});

it('allows distinct registrants for the same event', function () {
    $event = Event::factory()
        ->has(EventSettings::factory()->state([
            'registration_required' => true,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
            'registration_mode' => 'event',
            'capacity' => 10,
        ]), 'settings')
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $first = $this
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'Registrant',
            'email' => 'same@example.com',
        ]);

    $first->assertSessionHasNoErrors();

    $second = $this
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'Registrant Other',
            'email' => 'other@example.com',
        ]);

    $second->assertSessionHasNoErrors();

    expect(
        Registration::query()
            ->where('event_id', $event->id)
            ->where('status', 'registered')
            ->count()
    )->toBe(2);
});

it('allows authenticated users to register without email or phone', function () {
    $user = User::factory()->create();

    $event = Event::factory()
        ->has(EventSettings::factory()->state([
            'registration_required' => true,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
            'registration_mode' => 'event',
        ]), 'settings')
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $response = $this
        ->actingAs($user)
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'Authenticated Registrant',
        ]);

    $response->assertSessionHasNoErrors();

    $this->assertDatabaseHas('registrations', [
        'event_id' => $event->id,
        'user_id' => $user->id,
        'name' => 'Authenticated Registrant',
        'status' => 'registered',
    ]);
});

it('allows registration for unlisted events when registration is enabled', function () {
    $event = Event::factory()
        ->has(EventSettings::factory()->state([
            'registration_required' => true,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
            'registration_mode' => 'event',
        ]), 'settings')
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Unlisted,
            'published_at' => now(),
        ]);

    $response = $this
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'Unlisted Registrant',
            'email' => 'unlisted@example.com',
        ]);

    $response->assertSessionHasNoErrors();

    $this->assertDatabaseHas('registrations', [
        'event_id' => $event->id,
        'name' => 'Unlisted Registrant',
        'email' => 'unlisted@example.com',
        'status' => 'registered',
    ]);
});
