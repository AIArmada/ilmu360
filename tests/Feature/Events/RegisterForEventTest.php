<?php

use App\Models\Event;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a confirmed registration via the public api route', function () {
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
            'name' => 'Web Registrant',
            'email' => 'web@example.com',
        ]);

    $response->assertCreated();
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
