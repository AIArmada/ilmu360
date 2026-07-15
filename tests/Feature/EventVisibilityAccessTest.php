<?php

use App\Enums\EventVisibility;
use App\Livewire\Pages\Events\Show;
use App\Models\Event;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->otherUser = User::factory()->create();
});

// Public Event Tests
describe('public events', function () {
    it('allows anyone to view public events', function () {
        $event = Event::factory()->create([
            'visibility' => EventVisibility::Public,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        // Guest user
        Livewire::test(Show::class, ['event' => $event])
            ->assertSuccessful();
    });

    it('allows owner to view their public event', function () {
        $event = Event::factory()->create([
            'user_id' => $this->owner->id,
            'visibility' => EventVisibility::Public,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['event' => $event])
            ->assertSuccessful();
    });

    it('allows anyone to view cancelled public events', function () {
        $event = Event::factory()->create([
            'visibility' => EventVisibility::Public,
            'status' => 'cancelled',
            'published_at' => now(),
        ]);

        Livewire::test(Show::class, ['event' => $event])
            ->assertSuccessful();
    });

    it('allows calendar export for public approved events', function () {
        $event = Event::factory()->create([
            'visibility' => EventVisibility::Public,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        $this->get(route('events.calendar', $event))
            ->assertOk();
    });

    it('returns 404 for calendar export on cancelled public events', function () {
        $event = Event::factory()->create([
            'visibility' => EventVisibility::Public,
            'status' => 'cancelled',
            'published_at' => now(),
        ]);

        $this->get(route('events.calendar', $event))
            ->assertNotFound();
    });
});

// Unlisted Event Tests
describe('unlisted events', function () {
    it('allows anyone to view unlisted events via direct link', function () {
        $event = Event::factory()->create([
            'visibility' => EventVisibility::Unlisted,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        // Guest user
        Livewire::test(Show::class, ['event' => $event])
            ->assertSuccessful();
    });

    it('allows owner to view their unlisted event', function () {
        $event = Event::factory()->create([
            'submitter_id' => $this->owner->id,
            'visibility' => EventVisibility::Unlisted,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['event' => $event])
            ->assertSuccessful();
    });

    it('returns 404 for unlisted events when inactive', function () {
        $event = Event::factory()->create([
            'visibility' => EventVisibility::Unlisted,
            'status' => 'approved',
            'published_at' => null,
        ]);
        $event->updateQuietly(['published_at' => null]);

        Livewire::test(Show::class, ['event' => $event])
            ->assertStatus(404);
    });
});

// Private Event Tests
describe('private events', function () {
    it('allows owner to view their private event', function () {
        $event = Event::factory()->create([
            'user_id' => $this->owner->id,
            'visibility' => EventVisibility::Private,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['event' => $event])
            ->assertSuccessful();
    });

    it('allows submitter to view their private event', function () {
        $event = Event::factory()->create([
            'submitter_id' => $this->owner->id,
            'visibility' => EventVisibility::Private,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['event' => $event])
            ->assertSuccessful();
    });

    it('returns 404 for other users viewing private event', function () {
        $event = Event::factory()->create([
            'user_id' => $this->owner->id,
            'visibility' => EventVisibility::Private,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        Livewire::actingAs($this->otherUser)
            ->test(Show::class, ['event' => $event])
            ->assertStatus(404);
    });

    it('returns 404 for guests viewing private event', function () {
        $event = Event::factory()->create([
            'visibility' => EventVisibility::Private,
            'status' => 'approved',
            'published_at' => now(),
        ]);

        Livewire::test(Show::class, ['event' => $event])
            ->assertStatus(404);
    });

    it('allows owners to view their private events even when inactive', function () {
        $event = Event::factory()->create([
            'user_id' => $this->owner->id,
            'visibility' => EventVisibility::Private,
            'status' => 'approved',
            'published_at' => null,
        ]);
        $event->updateQuietly(['published_at' => null]);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['event' => $event])
            ->assertSuccessful();
    });
});

// Common Tests
describe('inactive or draft events', function () {
    it('returns 404 for inactive public events', function () {
        $event = Event::factory()->create([
            'visibility' => EventVisibility::Public,
            'status' => 'approved',
            'published_at' => null,
        ]);
        $event->updateQuietly(['published_at' => null]);

        Livewire::test(Show::class, ['event' => $event])
            ->assertStatus(404);
    });

});
