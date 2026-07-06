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
        ->withSession(['_token' => 'test-token'])
        ->post(route('events.register', $event), [
            '_token' => 'test-token',
            'name' => 'Authenticated Registrant',
        ]);

    $response->assertSessionHasNoErrors();

    $registration = Registration::query()
        ->where('event_id', $event->id)
        ->forUser($user)
        ->active()
        ->firstOrFail();

    expect($registration->resolvedName())
        ->toBe('Authenticated Registrant')
        ->and($registration->statusValue())->toBe('confirmed');
});

it('allows registration for unlisted events when registration is enabled', function () {
    $event = Event::factory()
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
