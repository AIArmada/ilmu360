<?php

use AIArmada\Events\Contracts\RegistrationServiceInterface;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a confirmed registration for a free public event via the package service', function () {
    $event = Event::factory()
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $eventRegistration = app(RegistrationServiceInterface::class)->register([
        'event_id' => $event->id,
        'registration_type' => 'individual',
        'status' => 'confirmed',
        'source' => 'free_rsvp',
        'total_participants' => 1,
        'total_amount' => null,
        'currency' => null,
        'payment_status' => null,
        'participants' => [[
            'name' => 'Test Registrant',
            'email' => 'test@example.com',
            'phone' => null,
            'is_purchaser' => true,
        ]],
    ]);

    $registration = Registration::findOrFail($eventRegistration->id);

    expect($registration)
        ->event_id->toBe($event->id)
        ->and($registration->statusValue())->toBe('confirmed');

    expect(Registration::query()->where('event_id', $event->id)->count())->toBe(1);
});

it('creates a confirmed registration via the public web route', function () {
    $event = Event::factory()
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $event->accessPolicy()->create([
        'registration_required' => true,
        'capacity' => 100,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    $response = $this
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'Web Registrant',
            'email' => 'web@example.com',
        ]);

    $response->assertSessionHasNoErrors();
    expect(Registration::query()->where('event_id', $event->id)->count())->toBe(1);
});

it('creates a confirmed registration via the api route', function () {
    $event = Event::factory()
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $event->accessPolicy()->create([
        'registration_required' => true,
        'capacity' => 100,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    $response = $this
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'API Registrant',
            'email' => 'api@example.com',
        ]);

    $response->assertStatus(201);
    expect(Registration::query()->where('event_id', $event->id)->count())->toBe(1);
});

it('allows authenticated users to register without email or phone', function () {
    $user = User::factory()->create();

    $event = Event::factory()
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $event->accessPolicy()->create([
        'registration_required' => true,
        'capacity' => 100,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    $response = $this
        ->actingAs($user)
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'Auth User',
        ]);

    $response->assertSessionHasNoErrors();

    expect(Registration::query()->where('event_id', $event->id)->count())->toBe(1);
});
