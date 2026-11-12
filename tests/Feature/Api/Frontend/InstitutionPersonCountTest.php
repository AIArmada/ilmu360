<?php

use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use App\Shared\Events\Enums\EventKeyPersonRole;
use App\Shared\Events\Enums\EventVisibility;

it('counts speakers from upcoming publicly listable events on the institution detail endpoint', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Kiraan Penceramah API',
        'status' => 'verified',
    ]);

    $createSpeakerEvent = function (array $attributes) use ($institution): Event {
        $event = Event::factory()->create([
            'institution_id' => $institution->getKey(),
            'visibility' => EventVisibility::Public->value,
            ...$attributes,
        ]);

        EventKeyPerson::factory()->create([
            'event_id' => $event->getKey(),
            'involveable_type' => 'person',
            'involveable_id' => Person::factory()->create(['status' => 'verified'])->getKey(),
            'role_code' => EventKeyPersonRole::Speaker->value,
            'visibility' => 'public',
        ]);

        return $event;
    };

    foreach (['approved', 'pending', 'cancelled'] as $status) {
        $createSpeakerEvent([
            'status' => $status,
            'starts_at' => now()->addDays(3)->setTime(19, 30),
            'ends_at' => now()->addDays(3)->setTime(21, 0),
        ]);
    }

    foreach (['draft', 'rejected'] as $status) {
        $createSpeakerEvent([
            'status' => $status,
            'starts_at' => now()->addDays(4)->setTime(19, 30),
            'ends_at' => now()->addDays(4)->setTime(21, 0),
        ]);
    }

    $createSpeakerEvent([
        'status' => 'approved',
        'starts_at' => now()->subDays(2)->setTime(19, 30),
        'ends_at' => now()->subDays(2)->setTime(21, 0),
    ]);

    $moderatorEvent = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'visibility' => EventVisibility::Public->value,
        'starts_at' => now()->addDays(5)->setTime(19, 30),
        'ends_at' => now()->addDays(5)->setTime(21, 0),
    ]);

    EventKeyPerson::factory()->create([
        'event_id' => $moderatorEvent->getKey(),
        'involveable_type' => 'person',
        'involveable_id' => Person::factory()->create(['status' => 'verified'])->getKey(),
        'role_code' => EventKeyPersonRole::Moderator->value,
        'visibility' => 'public',
    ]);

    $this->getJson(route('api.client.institutions.show', ['institutionKey' => $institution->slug]))
        ->assertOk()
        ->assertJsonPath('data.institution.person_count', 3);
});
