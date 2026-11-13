<?php

declare(strict_types=1);

use App\Livewire\Components\DirectoryCardFollow;
use App\Livewire\Components\EventCardEngagement;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Shared\Engagement\EngagementReader;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/**
 * D0 characterization: pin the engagement eligibility contracts that D4
 * resolved deliberately rather than accidentally.
 *
 * - Directory cards and the follow API agree: pending and verified public
 *   institutions/persons are followable. The API deliberately does not add
 *   the cards' speaker-status narrowing (D4d ruling).
 * - Event cards and the going API now share the inclusive effective-end
 *   window: an ongoing event (started past, ends future) stays going-eligible
 *   until `ends_at`, and a future event remains eligible.
 * - Save eligibility currently agrees: pending + public + published events
 *   are saveable from cards and the API.
 */
function d0EngagementCardParameters(Event $event): array
{
    return [
        'eventId' => (string) $event->getKey(),
        'returnUrl' => route('events.index'),
        'cardGoingAvailable' => true,
        'signalComponent' => 'd0_characterization',
        'cardSignalEntityType' => 'event',
        'cardSignalEntityId' => (string) $event->getKey(),
    ];
}

it('lets directory cards and the follow API follow pending public subjects', function (): void {
    $pendingInstitution = Institution::factory()->create(['status' => 'pending']);
    $pendingPerson = Person::factory()->create(['status' => 'pending']);
    $cardUser = User::factory()->create();

    $subjects = [
        'institution' => $pendingInstitution,
        'person' => $pendingPerson,
    ];

    foreach ($subjects as $type => $subject) {
        Livewire::actingAs($cardUser)
            ->test(DirectoryCardFollow::class, [
                'subjectType' => $type,
                'subjectId' => (string) $subject->getKey(),
                'returnUrl' => route('events.index'),
            ])
            ->call('toggleFollow', (string) $subject->getKey())
            ->assertSet('isFollowing', true);

        expect($cardUser->isFollowing($subject))->toBeTrue();
    }

    Sanctum::actingAs(User::factory()->create());

    foreach ($subjects as $type => $subject) {
        $this->postJson(route('api.client.follows.store', [
            'type' => $type,
            'subject' => $subject->slug,
        ]))->assertCreated()
            ->assertJsonPath('data.is_following', true);
    }
});

it('accepts ongoing events through the effective end on cards and in the going API', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subHours(2),
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    $cardUser = User::factory()->create();

    Livewire::actingAs($cardUser)
        ->test(EventCardEngagement::class, d0EngagementCardParameters($event))
        ->call('toggleGoing', (string) $event->getKey())
        ->assertSet('isGoing', true);

    expect(app(EngagementReader::class)->isGoingToEvent(
        (string) $cardUser->getKey(),
        (string) $event->getKey(),
    ))->toBeTrue();

    Sanctum::actingAs(User::factory()->create());

    $this->putJson(route('api.events.going.update', $event))
        ->assertCreated()
        ->assertJsonPath('data.is_going', true)
        ->assertJsonPath('data.going_count', 2);
});

it('saves pending public events on both cards and the save API', function (): void {
    $event = Event::factory()->create([
        'status' => 'pending',
        'visibility' => 'public',
        'published_at' => now()->subHour(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);

    $cardUser = User::factory()->create();

    Livewire::actingAs($cardUser)
        ->test(EventCardEngagement::class, d0EngagementCardParameters($event))
        ->call('toggleSave', (string) $event->getKey())
        ->assertSet('isSaved', true);

    expect(app(EngagementReader::class)->hasSavedEvent(
        (string) $cardUser->getKey(),
        (string) $event->getKey(),
    ))->toBeTrue();

    Sanctum::actingAs(User::factory()->create());

    $this->putJson(route('api.events.saved.update', $event))->assertCreated();
});
