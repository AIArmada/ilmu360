<?php

use AIArmada\Events\Actions\CreateEventOccurrenceAction;
use AIArmada\Events\Actions\CreateEventSessionAction;
use App\Livewire\Pages\Events\Index;
use App\Models\Event;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists meaningful sessions and otherwise falls back to occurrences', function (): void {
    $event = Event::factory()->create([
        'title' => 'Programme Parent',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);

    $occurrenceWithoutSessions = $event->occurrences()->firstOrFail();
    $occurrenceWithoutSessions->update([
        'title' => 'Occurrence Without Sessions',
        'slug' => 'occurrence-without-sessions',
    ]);

    $occurrenceWithSessions = app(CreateEventOccurrenceAction::class)->handle($event, [
        'title' => 'Occurrence With Sessions',
        'slug' => 'occurrence-with-sessions',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => 'published',
        'visibility' => 'public',
    ]);

    app(CreateEventSessionAction::class)->handle($occurrenceWithSessions, [
        'title' => 'Session One',
        'slug' => 'session-one',
        'starts_at' => now()->addDays(3)->addMinutes(15),
        'ends_at' => now()->addDays(3)->addHour(),
        'status' => 'published',
        'visibility' => 'public',
    ]);

    app(CreateEventSessionAction::class)->handle($occurrenceWithSessions, [
        'title' => 'Session Two',
        'slug' => 'session-two',
        'starts_at' => now()->addDays(3)->addHours(2),
        'ends_at' => now()->addDays(3)->addHours(3),
        'status' => 'published',
        'visibility' => 'public',
    ]);

    $response = $this->get(route('events.index'));

    $response->assertOk()
        ->assertSee('Session One')
        ->assertSee('Session Two')
        ->assertSee('Occurrence Without Sessions')
        ->assertDontSee('Occurrence With Sessions')
        ->assertSee(route('events.occurrence', [
            'event' => $event,
            'occurrenceSlug' => 'occurrence-without-sessions',
        ]), false)
        ->assertSee(route('events.session', [
            'event' => $event,
            'occurrenceSlug' => 'occurrence-with-sessions',
            'sessionSlug' => 'session-one',
        ]), false);

    $component = Livewire::test(Index::class);

    expect($component->instance()->scheduleItems->total())->toBe(3);
});

it('renders schedule locations whose venue is the package model', function (): void {
    $venue = Venue::factory()->create([
        'name' => 'Package Venue Card',
        'status' => 'verified',
    ]);
    $venue->primaryAddress()?->update([
        'city' => 'Package Venue City',
        'state' => 'Package Venue State',
    ]);
    $event = Event::factory()->create([
        'title' => 'Package Venue Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'institution_id' => null,
        'default_venue_id' => null,
    ]);
    $event->syncLocation($venue->getKey());

    $this->get(route('events.index', ['search' => 'Package Venue Programme']))
        ->assertOk()
        ->assertSee('Package Venue Programme')
        ->assertSee('Package Venue Card')
        ->assertSee('Package Venue City');
});

it('renders scoped occurrence and session pages while preserving the programme hub', function (): void {
    $event = Event::factory()->create([
        'title' => 'Programme Hub',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $occurrence = $event->occurrences()->firstOrFail();
    $occurrence->update([
        'title' => 'Programme Day One',
        'slug' => 'day-one',
    ]);
    $session = app(CreateEventSessionAction::class)->handle($occurrence, [
        'title' => 'Tafsir Al-Kahfi',
        'slug' => 'tafsir-al-kahfi',
        'starts_at' => now()->addDays(2)->addHour(),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => 'published',
        'visibility' => 'public',
    ]);

    $this->get(route('events.occurrence', [
        'event' => $event,
        'occurrenceSlug' => 'day-one',
    ]))
        ->assertOk()
        ->assertSee('Programme Day One')
        ->assertSee('Tafsir Al-Kahfi')
        ->assertSee('View programme');

    $this->get(route('events.session', [
        'event' => $event,
        'occurrenceSlug' => 'day-one',
        'sessionSlug' => 'tafsir-al-kahfi',
    ]))
        ->assertOk()
        ->assertSee('Tafsir Al-Kahfi')
        ->assertSee('Programme Day One')
        ->assertSee('View occurrence');

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('Programme Day One')
        ->assertSee($session->title);
});

it('renders occurrence and session pages when the location points at a venue', function (): void {
    $venue = Venue::factory()->create([
        'name' => 'Venue With Address',
        'status' => 'verified',
    ]);
    $venue->primaryAddress()?->update([
        'city' => 'Location Venue City',
        'state' => 'Location Venue State',
    ]);
    $event = Event::factory()->create([
        'title' => 'Venue Location Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'institution_id' => null,
        'default_venue_id' => null,
    ]);
    $event->syncLocation($venue->getKey());
    $occurrence = $event->occurrences()->firstOrFail();
    $occurrence->update([
        'slug' => 'venue-location-day',
    ]);
    $session = app(CreateEventSessionAction::class)->handle($occurrence, [
        'title' => 'Venue Location Session',
        'slug' => 'venue-location-session',
        'starts_at' => now()->addDays(2)->addHour(),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => 'published',
        'visibility' => 'public',
    ]);

    $this->get(route('events.occurrence', [
        'event' => $event,
        'occurrenceSlug' => 'venue-location-day',
    ]))
        ->assertOk()
        ->assertSee('Venue With Address')
        ->assertSee('Location Venue City');

    $this->get(route('events.session', [
        'event' => $event,
        'occurrenceSlug' => 'venue-location-day',
        'sessionSlug' => $session->slug,
    ]))
        ->assertOk()
        ->assertSee('Venue With Address')
        ->assertSee('Location Venue City');
});

it('does not expose private child schedules or allow cross-parent slug traversal', function (): void {
    $event = Event::factory()->create([
        'title' => 'Public Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $occurrence = $event->occurrences()->firstOrFail();
    $occurrence->update([
        'title' => 'Public Occurrence',
        'slug' => 'public-occurrence',
    ]);
    $session = app(CreateEventSessionAction::class)->handle($occurrence, [
        'title' => 'Private Session',
        'slug' => 'private-session',
        'starts_at' => now()->addDays(2)->addHour(),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => 'draft',
        'visibility' => 'public',
    ]);

    $this->get(route('events.index', ['search' => 'Public Programme']))
        ->assertOk()
        ->assertDontSee(route('events.session', [
            'event' => $event,
            'occurrenceSlug' => 'public-occurrence',
            'sessionSlug' => 'private-session',
        ]), false)
        ->assertSee($occurrence->title);

    $otherEvent = Event::factory()->create([
        'title' => 'Other Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);

    $this->get(route('events.occurrence', [
        'event' => $otherEvent,
        'occurrenceSlug' => 'public-occurrence',
    ]))->assertNotFound();
});
