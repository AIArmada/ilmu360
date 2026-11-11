<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Models\Bookmark;
use App\Shared\Events\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Person;
use App\Models\User;
use Livewire\Livewire;

function linkSpeakerToEvent(Person $person, Event $event): void
{
    EventKeyPerson::query()->create([
        'event_id' => $event->getKey(),
        'involveable_type' => 'person',
        'involveable_id' => $person->getKey(),
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);
}

it('saves and unsaves an event from the person page majlis card', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    linkSpeakerToEvent($person, $event);

    $component = Livewire::actingAs($user)
        ->test('pages.persons.show', ['person' => $person])
        ->assertSee($event->title)
        ->assertSee('data-save-state="unsaved"', false)
        ->call('toggleSave', $event->getKey())
        ->assertSee('data-save-state="saved"', false);

    expect(OwnerContext::withOwner(null, fn (): bool => Bookmark::forBookmarker($user)
        ->forBookmarkable($event)
        ->active()
        ->exists()))->toBeTrue();

    $component->call('toggleSave', $event->getKey())
        ->assertSee('data-save-state="unsaved"', false);

    expect(OwnerContext::withOwner(null, fn (): bool => Bookmark::forBookmarker($user)
        ->forBookmarkable($event)
        ->active()
        ->exists()))->toBeFalse();
});

it('redirects guests to login when saving from the person page majlis card', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    linkSpeakerToEvent($person, $event);

    Livewire::test('pages.persons.show', ['person' => $person])
        ->call('toggleSave', $event->getKey())
        ->assertRedirect(route('login', ['redirect' => route('persons.show', $person, false)]));
});
