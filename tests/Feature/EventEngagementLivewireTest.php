<?php

use AIArmada\Engagement\Contracts\EngagementCounterService;
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
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.events.show', ['event' => $event])
        ->assertSet('isSaved', false);

    $component->call('toggleSave')
        ->assertSet('isSaved', true);

    $this->assertDatabaseHas('engagement_bookmarks', [
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'bookmarkable_type' => $event->getMorphClass(),
        'bookmarkable_id' => $event->id,
        'status' => 'active',
    ]);

    $component->call('toggleSave')
        ->assertSet('isSaved', false);

    $this->assertDatabaseMissing('engagement_bookmarks', [
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'bookmarkable_type' => $event->getMorphClass(),
        'bookmarkable_id' => $event->id,
        'status' => 'active',
    ]);

    expect(app(EngagementCounterService::class)->countBookmarks($event))->toBe(0);
});

it('toggles event saves from the events index cards', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(7),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.events.index');

    $component->call('toggleSave', $event->id);

    $this->assertDatabaseHas('engagement_bookmarks', [
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'bookmarkable_type' => $event->getMorphClass(),
        'bookmarkable_id' => $event->id,
        'status' => 'active',
    ]);

    $component->call('toggleSave', $event->id);

    $this->assertDatabaseMissing('engagement_bookmarks', [
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'bookmarkable_type' => $event->getMorphClass(),
        'bookmarkable_id' => $event->id,
        'status' => 'active',
    ]);

    expect(app(EngagementCounterService::class)->countBookmarks($event))->toBe(0);
});

it('toggles event going from the events index cards', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHours(2),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.events.index');

    $component->call('toggleGoing', $event->id);

    expect($event->goingBy()->forResponder($user)->active()->exists())->toBeTrue();
    expect(app(EngagementCounterService::class)->countResponses($event, 'going'))->toBe(1);

    $component->call('toggleGoing', $event->id);

    expect($event->goingBy()->forResponder($user)->active()->exists())->toBeFalse();
    expect(app(EngagementCounterService::class)->countResponses($event, 'going'))->toBe(0);
});

it('toggles event going from the featured homepage cards', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.home')
        ->assertSee('data-going-icon="event"', false)
        ->assertSee('data-going-state="not-going"', false)
        ->assertSee('data-save-icon="event"', false)
        ->assertDontSee('data-signal-event="share.event_clicked"', false);

    $component->call('toggleGoing', $event->id)
        ->assertSee('data-going-state="going"', false);

    expect($event->goingBy()->forResponder($user)->active()->exists())->toBeTrue();

    $component->call('toggleGoing', $event->id)
        ->assertSee('data-going-state="not-going"', false);

    expect($event->goingBy()->forResponder($user)->active()->exists())->toBeFalse();
});

it('redirects guests to login when going from the events index cards', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHours(2),
    ]);

    Livewire::test('pages.events.index')
        ->call('toggleGoing', (string) $event->id)
        ->assertRedirect(route('login', ['redirect' => route('events.index', absolute: false)]));

    expect($event->goingBy()->active()->exists())->toBeFalse();
});
