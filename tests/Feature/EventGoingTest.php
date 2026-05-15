<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;

describe('Event Going Feature', function () {
    it('allows a user to mark as going to an event', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['going_count' => 0]);

        $event->goingBy()->attach($user->id);
        $event->increment('going_count');

        expect($event->fresh()->going_count)->toBe(1);
        expect($event->goingBy()->where('user_id', $user->id)->exists())->toBeTrue();
    });

    it('allows a user to unmark as going to an event', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['going_count' => 1]);

        $event->goingBy()->attach($user->id);

        $event->goingBy()->detach($user->id);
        $event->decrement('going_count');

        expect($event->fresh()->going_count)->toBe(0);
        expect($event->goingBy()->where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('user can access events they are going to', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        $user->goingEvents()->attach($event->id);

        expect($user->goingEvents()->count())->toBe(1);
        expect($user->goingEvents()->first()->id)->toBe($event->id);
    });

    it('going and saved are independent', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'going_count' => 0,
            'saves_count' => 0,
        ]);

        // User can be going but not saved
        $event->goingBy()->attach($user->id);
        $event->increment('going_count');

        expect($event->fresh()->going_count)->toBe(1);
        expect($event->fresh()->saves_count)->toBe(0);

        // User can also save independently
        $event->savedBy()->attach($user->id);
        $event->increment('saves_count');

        expect($event->fresh()->going_count)->toBe(1);
        expect($event->fresh()->saves_count)->toBe(1);

        // Both should be true for this user
        expect($event->goingBy()->where('user_id', $user->id)->exists())->toBeTrue();
        expect($event->savedBy()->where('user_id', $user->id)->exists())->toBeTrue();
    });

    it('multiple users can be going to the same event', function () {
        $users = User::factory()->count(3)->create();
        $event = Event::factory()->create(['going_count' => 0]);

        foreach ($users as $user) {
            $event->goingBy()->attach($user->id);
            $event->increment('going_count');
        }

        expect($event->fresh()->going_count)->toBe(3);
        expect($event->goingBy()->count())->toBe(3);
    });

    it('user cannot be going to the same event twice', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['going_count' => 0]);

        $event->goingBy()->attach($user->id);

        // Attempting to attach again should fail due to primary key constraint
        expect(fn () => $event->goingBy()->attach($user->id))
            ->toThrow(QueryException::class);
    });
});
