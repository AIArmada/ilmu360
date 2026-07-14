<?php

use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('provides canonical attendance attributes and metadata from its factory', function () {
    $checkin = EventCheckin::factory()->create();

    expect($checkin->attendee_id)->not->toBeNull()
        ->and($checkin->attendee_type)->toBe((new User)->getMorphClass())
        ->and($checkin->check_in_source)->toBeIn(['self_reported', 'registered_self_checkin', 'organizer_verified'])
        ->and($checkin->metadata)->toHaveKeys(['lat', 'lng', 'accuracy_m'])
        ->and($checkin->checked_in_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($checkin->getRawOriginal('user_id'))->toBeNull()
        ->and($checkin->getRawOriginal('method'))->toBeNull();
});

it('allows logged in users to self check in for open events within check-in window', function () {
    $user = User::factory()->create();
    $startsAt = now('Asia/Kuala_Lumpur')->addHour()->utc();

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $event->accessPolicy()->delete();

    Livewire::actingAs($user)
        ->test('pages.events.show', ['event' => $event])
        ->call('checkIn')
        ->assertSet('isCheckedIn', true);

    expect(EventCheckin::query()
        ->where('event_id', $event->id)
        ->where('attendee_id', $user->id)
        ->where('check_in_source', 'self_reported')
        ->count())->toBe(1);
});

it('requires registration before check-in when event requires registration', function () {
    $user = User::factory()->create();
    $startsAt = now('Asia/Kuala_Lumpur')->addHour()->utc();

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $event->accessPolicy()->updateOrCreate([], [
        'registration_required' => true,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    Livewire::actingAs($user)
        ->test('pages.events.show', ['event' => $event])
        ->call('checkIn')
        ->assertSet('isCheckedIn', false);

    expect(EventCheckin::query()->where('event_id', $event->id)->where('attendee_id', $user->id)->exists())->toBeFalse();
});

it('allows check-in for registered users when event requires registration', function () {
    $user = User::factory()->create();
    $startsAt = now('Asia/Kuala_Lumpur')->addHour()->utc();

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $event->accessPolicy()->updateOrCreate([], [
        'registration_required' => true,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    $registration = Registration::factory()
        ->forRegistrant($user)
        ->create([
            'event_id' => $event->id,
            'status' => 'confirmed',
        ]);

    Livewire::actingAs($user)
        ->test('pages.events.show', ['event' => $event])
        ->call('checkIn')
        ->assertSet('isCheckedIn', true);

    $checkin = EventCheckin::query()
        ->where('event_id', $event->id)
        ->where('attendee_id', $user->id)
        ->latest('checked_in_at')
        ->first();

    expect($checkin)->not->toBeNull()
        ->and($checkin?->check_in_source)->toBe('registered_self_checkin')
        ->and($checkin?->event_registration_id)->toBe($registration->id);
});

it('redirects guests to login when trying to check in', function () {
    $startsAt = now('Asia/Kuala_Lumpur')->addHour()->utc();

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    Livewire::test('pages.events.show', ['event' => $event])
        ->call('checkIn')
        ->assertRedirect(route('login', ['redirect' => route('events.show', $event, absolute: false)]));
});

it('prevents duplicate check-ins for the same event and user', function () {
    $user = User::factory()->create();
    $startsAt = now('Asia/Kuala_Lumpur')->addHour()->utc();

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    $event->accessPolicy()->delete();

    Livewire::actingAs($user)
        ->test('pages.events.show', ['event' => $event])
        ->call('checkIn')
        ->call('checkIn')
        ->assertSet('isCheckedIn', true);

    expect(EventCheckin::query()
        ->where('event_id', $event->id)
        ->where('attendee_id', $user->id)
        ->count())->toBe(1);
});
