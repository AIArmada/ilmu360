<?php

use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows multiple registrations for the same event', function () {
    $event = Event::factory()
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $first = $this
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Registrant',
            'email' => 'same@example.com',
        ]);

    $first->assertCreated();

    $second = $this
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Registrant Other',
            'email' => 'other@example.com',
        ]);

    $second->assertCreated();

    expect(
        Registration::query()
            ->where('event_id', $event->id)
            ->where('status', 'confirmed')
            ->count()
    )->toBe(2);
});

it('allows authenticated users to register without email or phone', function () {
    $user = User::factory()->create();

    $event = Event::factory()
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $response = $this
        ->actingAs($user)
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Authenticated Registrant',
        ]);

    $response->assertCreated();

    $registration = Registration::query()
        ->where('event_id', $event->id)
        ->forUser($user)
        ->active()
        ->firstOrFail();

    expect($registration->resolvedName())
        ->toBe('Authenticated Registrant')
        ->and($registration->statusValue())->toBe('confirmed');
});

it('rejects guest registration without email or phone on the web form', function () {
    $event = Event::factory()
        ->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

    $response = $this
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Guest Registrant',
        ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['contact']);

    expect(Registration::query()->where('event_id', $event->id)->count())->toBe(0);
});

it('allows registration for unlisted events when registration is enabled', function () {
    $event = Event::factory()
        ->create([
            'status' => 'approved',
            'visibility' => EventVisibility::Unlisted,
            'published_at' => now(),
        ]);

    $response = $this
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Unlisted Registrant',
            'email' => 'unlisted@example.com',
        ]);

    $response->assertCreated();

    $registration = Registration::query()
        ->where('event_id', $event->id)
        ->forPrimaryContact('unlisted@example.com')
        ->active()
        ->firstOrFail();

    expect($registration->resolvedName())
        ->toBe('Unlisted Registrant')
        ->and($registration->resolvedEmail())->toBe('unlisted@example.com')
        ->and($registration->statusValue())->toBe('confirmed');
});
