<?php

use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('can create an event member', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    $member = EventUser::factory()
        ->for($event)
        ->for($user)
        ->create();

    expect($member->event_id)->toBe($event->id)
        ->and($member->user_id)->toBe($user->id);
});

it('has event relationship', function () {
    $member = EventUser::factory()->create();

    expect($member->event)->toBeInstanceOf(Event::class);
});

it('has user relationship', function () {
    $member = EventUser::factory()->create();

    expect($member->user)->toBeInstanceOf(User::class);
});

it('enforces unique event and user combination', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    EventUser::factory()->for($event)->for($user)->create();

    // Attempting to create duplicate should fail
    EventUser::factory()->for($event)->for($user)->create();
})->throws(UniqueConstraintViolationException::class);

it('can access members from event', function () {
    $event = Event::factory()->create();
    $users = User::factory()->count(3)->create();

    foreach ($users as $user) {
        EventUser::factory()->for($event)->for($user)->create();
    }

    expect($event->members)->toHaveCount(3);
});

it('can access member events from user', function () {
    $user = User::factory()->create();
    $events = Event::factory()->count(2)->create();

    foreach ($events as $event) {
        EventUser::factory()->for($event)->for($user)->create();
    }

    expect($user->memberEvents)->toHaveCount(2);
});

it('includes pivot data in relationships', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();

    EventUser::factory()
        ->for($event)
        ->for($user)
        ->create(['joined_at' => now()]);

    $memberFromEvent = $event->members->first();

    expect(data_get($memberFromEvent, 'pivot.joined_at'))->not->toBeNull();
});
