<?php

use AIArmada\Engagement\Contracts\EngagementCounterService;
use App\Livewire\Components\EventCardEngagement;
use App\Models\Event;
use App\Models\User;
use App\Services\PublicScheduleDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function engagementCardParameters(Event $event): array
{
    return [
        'eventId' => (string) $event->id,
        'returnUrl' => route('events.index'),
        'cardGoingAvailable' => true,
        'signalComponent' => 'events_index_results',
        'cardSignalEntityType' => 'event',
        'cardSignalEntityId' => (string) $event->id,
    ];
}

it('updates card engagement and counters without querying schedule discovery', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved', 'visibility' => 'public', 'published_at' => now(),
        'starts_at' => now()->addDays(7), 'ends_at' => now()->addDays(7)->addHours(2),
    ]);
    $this->app->bind(PublicScheduleDiscoveryService::class, fn () => throw new LogicException('Card actions must not rebuild schedule discovery.'));

    $card = Livewire::actingAs($user)->test(EventCardEngagement::class, engagementCardParameters($event));
    $card->call('toggleSave', (string) $event->id)
        ->assertSet('isSaved', true)
        ->assertSee('data-save-state="saved"', false)
        ->assertNotDispatched('event-card-list-changed');
    expect(app(EngagementCounterService::class)->countBookmarks($event))->toBe(1);
    $card->call('toggleGoing', (string) $event->id)->assertSet('isGoing', true);
    expect($event->goingBy()->forResponder($user)->active()->exists())->toBeTrue();
    expect(app(EngagementCounterService::class)->countResponses($event, 'going'))->toBe(1);
    $card->call('toggleSave', (string) $event->id)->assertSet('isSaved', false);
    $card->call('toggleGoing', (string) $event->id)->assertSet('isGoing', false);
    expect(app(EngagementCounterService::class)->countBookmarks($event))->toBe(0);
    expect(app(EngagementCounterService::class)->countResponses($event, 'going'))->toBe(0);
});

it('refreshes filtered listings and synchronizes another occurrence card', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create(['status' => 'approved', 'visibility' => 'public', 'starts_at' => now()->addDays(7)]);
    $parameters = engagementCardParameters($event);
    $other = Livewire::actingAs($user)->test(EventCardEngagement::class, $parameters);
    Livewire::actingAs($user)->test(EventCardEngagement::class, [...$parameters, 'refreshListing' => true])
        ->call('toggleSave', (string) $event->id)
        ->assertDispatched('event-card-list-changed');
    $other->dispatch("event-card-engagement-updated.{$event->id}", origin: 'another-card')
        ->assertSet('isSaved', true);
});

it('redirects guest card actions to login with the original listing', function (string $action) {
    $event = Event::factory()->create();
    Livewire::test(EventCardEngagement::class, engagementCardParameters($event))
        ->call($action, (string) $event->id)
        ->assertRedirect(route('login', ['redirect' => route('events.index', absolute: false)]));
})->with(['toggleSave', 'toggleGoing']);

it('does not allow a card to mutate another event', function () {
    $event = Event::factory()->create();
    Livewire::actingAs(User::factory()->create())
        ->test(EventCardEngagement::class, engagementCardParameters($event))
        ->call('toggleSave', 'another-event')->assertForbidden();
});
