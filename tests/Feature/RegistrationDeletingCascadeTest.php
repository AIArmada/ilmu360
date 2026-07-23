<?php

use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes registration scoped checkins when registration is deleted', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();
    $registration = Registration::factory()->for($event)->forRegistrant($user)->create();
    $checkin = EventCheckin::factory()->for($event)->create([
        'attendee_type' => $user->getMorphClass(),
        'attendee_id' => $user->id,
        'event_registration_id' => $registration->id,
    ]);

    $registrationId = $registration->id;

    $registration->delete();

    expect(EventCheckin::query()->where('event_registration_id', $registrationId)->exists())->toBeFalse();
});
