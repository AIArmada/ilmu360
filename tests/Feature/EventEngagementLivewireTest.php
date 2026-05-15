<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('toggles event saves via livewire actions', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(7),
        'saves_count' => 0,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.events.show', ['event' => $event])
        ->assertSet('isSaved', false);

    $component->call('toggleSave')
        ->assertSet('isSaved', true);

    $this->assertDatabaseHas('event_saves', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);

    $component->call('toggleSave')
        ->assertSet('isSaved', false);

    $this->assertDatabaseMissing('event_saves', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);

    $event->refresh();
    expect($event->saves_count)->toBe(0);
});

it('toggles event saves from the events index cards', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(7),
        'saves_count' => 0,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.events.index');

    $component->call('toggleSave', $event->id);

    $this->assertDatabaseHas('event_saves', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);

    $component->call('toggleSave', $event->id);

    $this->assertDatabaseMissing('event_saves', [
        'user_id' => $user->id,
        'event_id' => $event->id,
    ]);

    $event->refresh();
    expect($event->saves_count)->toBe(0);
});
