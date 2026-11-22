<?php

use AIArmada\Events\Actions\CreateEventOccurrenceAction;
use AIArmada\Events\Actions\CreateEventSessionAction;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventReference;
use App\Features\Discovery\Api\Data\EventListData;
use App\Features\Discovery\Api\Data\EventSearchPage;
use App\Features\Events\Support\PublicScheduleLeaf;
use App\Features\Discovery\Services\EventSearchService;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\Api\EventScheduleFact;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\EntryPoints\Livewire\Pages\Index;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Features\Events\Services\PublicScheduleDiscoveryService;
use App\Features\Institutions\EntryPoints\Livewire\Pages\Show;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Venue;
use App\Features\Prayer\Api\PrayerOffset;
use App\Features\Prayer\Api\PrayerReference;
use App\Features\References\Models\Reference;
use App\Platform\Location\VisitorCountryResolver;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function (): void {
    app(VisitorCountryResolver::class)->forget();
});

it('lists occurrences with meaningful sessions nested under their occurrence', function (): void {
    $event = Event::factory()->create([
        'title' => 'Programme Parent',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'delivery_mode' => EventFormat::Physical->value,
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
        ->assertSee('Occurrence With Sessions')
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

    expect($component->instance()->scheduleItems->total())->toBe(2);
});

it('filters immutable schedule facts before hydrating the selected page leaves', function (): void {
    $event = Event::factory()->create([
        'title' => 'Immutable Fact Schedule',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $publicOccurrence = $event->occurrences()->firstOrFail();
    $session = app(CreateEventSessionAction::class)->handle($publicOccurrence, [
        'title' => 'Fact Session',
        'slug' => 'fact-session',
        'starts_at' => now()->addDays(2)->addHour(),
        'status' => 'published',
        'visibility' => 'public',
    ]);
    app(CreateEventOccurrenceAction::class)->handle($event, [
        'title' => 'Hidden Draft Occurrence',
        'slug' => 'hidden-draft-occurrence',
        'starts_at' => now()->addDays(3),
        'status' => 'draft',
        'visibility' => 'private',
    ]);
    Event::factory()->create([
        'title' => 'Private Parent Event',
        'status' => 'approved',
        'visibility' => 'private',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);

    $factSet = app(EventScheduleReads::class)->publicScheduleFactSet(null, ['time_scope' => 'all']);
    $eventFacts = collect($factSet->leaves)
        ->where('eventId', (string) $event->getKey())
        ->values();
    $page = app(PublicScheduleDiscoveryService::class)->search(
        query: 'Immutable Fact Schedule',
        filters: ['time_scope' => 'all'],
        perPage: 1,
    );
    $leaf = $page->items()[0];

    expect($eventFacts)->toHaveCount(1)
        ->and($eventFacts->first())->toBeInstanceOf(EventScheduleFact::class)
        ->and(new ReflectionClass(EventScheduleFact::class)->isReadOnly())->toBeTrue()
        ->and($eventFacts->first()->sessionIds)->toBe([(string) $session->getKey()])
        ->and($page->total())->toBe(1)
        ->and($leaf)->toBeInstanceOf(PublicScheduleLeaf::class)
        ->and($leaf->event()->getKey())->toBe($event->getKey())
        ->and($leaf->occurrence()->getKey())->toBe($publicOccurrence->getKey());
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
        'delivery_mode' => EventFormat::Physical->value,
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

    // A second date makes the event URL a real programme hub, distinct from
    // the date page; a single-date event renders the date page at both URLs.
    app(CreateEventOccurrenceAction::class)->handle($event, [
        'title' => 'Programme Day Two',
        'slug' => 'day-two',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => 'published',
        'visibility' => 'public',
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
        ->assertSee('Program penuh')
        ->assertSee('Semua tarikh dan sesi');

    $this->get(route('events.session', [
        'event' => $event,
        'occurrenceSlug' => 'day-one',
        'sessionSlug' => 'tafsir-al-kahfi',
    ]))
        ->assertOk()
        ->assertSee('Tafsir Al-Kahfi')
        ->assertSee('Programme Day One')
        ->assertSee('Lihat tarikh')
        ->assertSee('Buka halaman majlis');

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

it('renders occurrence and session pages from their own scoped location venues', function (): void {
    $occurrenceVenue = Venue::factory()->create(['name' => 'Dewan Occurrence Own']);
    $occurrenceVenue->primaryAddress()?->update([
        'city' => 'Occurrence Own City',
        'state' => 'Occurrence Own State',
    ]);
    $sessionVenue = Venue::factory()->create(['name' => 'Dewan Session Own']);
    $sessionVenue->primaryAddress()?->update([
        'city' => 'Session Own City',
        'state' => 'Session Own State',
    ]);

    $event = Event::factory()->create([
        'title' => 'Scoped Venue Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'institution_id' => null,
        'default_venue_id' => null,
    ]);
    $occurrence = $event->occurrences()->firstOrFail();
    $occurrence->update(['slug' => 'scoped-venue-day']);

    EventLocation::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $occurrence->getKey(),
        'event_session_id' => null,
        'location_role' => 'primary',
        'venue_id' => $occurrenceVenue->getKey(),
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);

    $session = app(CreateEventSessionAction::class)->handle($occurrence, [
        'title' => 'Scoped Venue Session',
        'slug' => 'scoped-venue-session',
        'starts_at' => now()->addDays(2)->addHour(),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => 'published',
        'visibility' => 'public',
    ]);

    EventLocation::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $occurrence->getKey(),
        'event_session_id' => $session->getKey(),
        'location_role' => 'primary',
        'venue_id' => $sessionVenue->getKey(),
        'visibility' => 'public',
        'status' => 'active',
        'sort_order' => 0,
    ]);

    // Scoped location names use package venue identity; primary addresses
    // resolve through the Places scalar catalog, never an ORM transport port.
    $this->get(route('events.occurrence', [
        'event' => $event,
        'occurrenceSlug' => 'scoped-venue-day',
    ]))
        ->assertOk()
        ->assertSee('Dewan Occurrence Own')
        ->assertSee('Occurrence Own City');

    $this->get(route('events.session', [
        'event' => $event,
        'occurrenceSlug' => 'scoped-venue-day',
        'sessionSlug' => 'scoped-venue-session',
    ]))
        ->assertOk()
        ->assertSee('Dewan Session Own')
        ->assertSee('Session Own City');
});

it('does not expose private child schedules or allow cross-parent slug traversal', function (): void {
    $event = Event::factory()->create([
        'title' => 'Public Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        // Pin physical so the event carries an institution address and stays
        // inside the default country scope; online events have no location
        // signal and fall outside it.
        'delivery_mode' => EventFormat::Physical->value,
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

it('lists scoped schedule leaves on institution, reference and speaker pages', function (): void {
    $this->travelTo(now()->startOfDay());
    $institution = Institution::factory()->create(['status' => 'verified']);
    $reference = Reference::factory()->create(['status' => 'verified']);
    $speaker = Person::factory()->create(['status' => 'verified']);
    $otherSpeaker = Person::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'title' => 'Scoped Parent Programme',
        'institution_id' => $institution->getKey(),
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $occurrence = $event->occurrences()->firstOrFail();
    $occurrence->update(['title' => 'Parent Occurrence', 'slug' => 'scoped-profile-day']);
    $first = app(CreateEventSessionAction::class)->handle($occurrence, [
        'title' => 'Matching Profile Session',
        'slug' => 'matching-profile-session',
        'starts_at' => now()->addDays(2)->addHour(),
        'status' => 'published',
        'visibility' => 'public',
    ]);
    $second = app(CreateEventSessionAction::class)->handle($occurrence, [
        'title' => 'Different Profile Session',
        'slug' => 'different-profile-session',
        'starts_at' => now()->addDays(3),
        'status' => 'published',
        'visibility' => 'public',
    ]);
    foreach ([[$first, $speaker], [$second, $otherSpeaker]] as [$session, $person]) {
        EventKeyPerson::query()->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'event_session_id' => $session->getKey(),
            'involveable_type' => $person->getMorphClass(),
            'involveable_id' => $person->getKey(),
            'role_code' => 'speaker',
            'status' => 'active',
            'visibility' => 'public',
        ]);
    }
    EventReference::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $occurrence->getKey(),
        'event_session_id' => $first->getKey(),
        'referenceable_type' => $reference->getMorphClass(),
        'referenceable_id' => $reference->getKey(),
        'reference_type' => 'book',
        'title' => $reference->title,
        'visibility' => 'public',
    ]);

    $this->get(route('institutions.show', $institution))->assertOk()
        ->assertSee('Parent Occurrence')
        ->assertSee('Matching Profile Session')
        ->assertSee('Different Profile Session');
    $this->get(route('references.show', $reference))->assertOk()
        ->assertSee('Parent Occurrence')
        ->assertSee('Matching Profile Session')
        ->assertDontSee('Different Profile Session');
    $this->get(route('persons.show', $speaker))->assertOk()
        ->assertSee('Parent Occurrence')
        ->assertSee('Matching Profile Session')
        ->assertDontSee('Different Profile Session');

    $component = Livewire::withCookie('user_timezone', 'UTC')
        ->test(Show::class, ['institution' => $institution]);
    expect($component->instance()->getUpcomingTotalProperty())->toBe(1);
    $component->set('customStartDate', now()->addDays(2)->toDateString())
        ->set('customEndDate', now()->addDays(2)->toDateString())
        ->call('applyCustomDateRange')
        ->assertSee('Parent Occurrence')
        ->assertSee('Matching Profile Session')
        ->assertSee('Different Profile Session');
    expect($component->instance()->getUpcomingTotalProperty())->toBe(1);
});

it('renders localized inherited prayer timing and keeps session-specific labels scoped', function (): void {
    $previousLocale = app()->getLocale();
    app()->setLocale('en');

    try {
        $institution = Institution::factory()->create(['status' => 'verified']);
        $event = Event::factory()->create([
            'title' => 'Prayer Timing Programme',
            'institution_id' => $institution->getKey(),
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDays(2),
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_reference' => PrayerReference::Isha,
            'prayer_offset' => PrayerOffset::After60,
            'prayer_display_text' => 'Selepas Tarawih',
        ]);
        $occurrence = $event->occurrences()->firstOrFail();
        $sessionOne = app(CreateEventSessionAction::class)->handle($occurrence, [
            'title' => 'Session Specific Prayer',
            'slug' => 'session-specific-prayer',
            'starts_at' => now()->addDays(2)->addHour(),
            'status' => 'published',
            'visibility' => 'public',
        ]);
        $sessionTwo = app(CreateEventSessionAction::class)->handle($occurrence, [
            'title' => 'Session Inherited Prayer',
            'slug' => 'session-inherited-prayer',
            'starts_at' => now()->addDays(2)->addHours(2),
            'status' => 'published',
            'visibility' => 'public',
        ]);
        $sessionOne->timeExpressions()->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'time_mode' => 'prayer_relative',
            'anchor_type' => 'prayer',
            'display_label' => 'Waktu Sesi Khas',
        ]);

        $html = (string) $this->get(route('institutions.show', $institution))->assertOk()->getContent();
        preg_match('/<article[^>]*wire:key="upcoming-occurrence-'.preg_quote((string) $occurrence->getKey(), '/').'"[^>]*>.*?<\/article>/s', $html, $card);

        expect($card[0] ?? null)->not->toBeNull()
            ->toContain('Waktu Sesi Khas')
            ->toContain('After Tarawih')
            ->toContain('Session Specific Prayer')
            ->toContain('Session Inherited Prayer');
    } finally {
        app()->setLocale($previousLocale);
    }
});

it('rejects rehydrated search ids whose events are no longer publicly visible', function (): void {
    $private = Event::factory()->create([
        'title' => 'Stale Private Rehydration Programme',
        'status' => 'approved',
        'visibility' => 'private',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $unpublished = Event::factory()->create([
        'title' => 'Stale Unpublished Rehydration Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);
    $unpublished->updateQuietly(['published_at' => null]);
    $public = Event::factory()->create([
        'title' => 'Current Public Rehydration Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(3),
    ]);

    $factSet = app(EventScheduleReads::class)->scheduleFactsForEventIds([
        (string) $private->getKey(),
        (string) $unpublished->getKey(),
        (string) $public->getKey(),
    ]);

    expect($factSet->eventIds)->toBe([(string) $public->getKey()])
        ->and(collect($factSet->leaves)->pluck('eventId')->unique()->values()->all())
        ->toBe([(string) $public->getKey()]);
});

it('does not render stale search ids from the discovery fallback or nearby path', function (): void {
    $private = Event::factory()->create([
        'title' => 'Stale Fallback Private Programme',
        'status' => 'approved',
        'visibility' => 'private',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $privateEventId = (string) $private->getKey();
    $page = new EventSearchPage(
        items: [EventListData::fromArray(['id' => $privateEventId])],
        distances: [$privateEventId => 2.5],
        total: 1,
        perPage: 12,
        currentPage: 1,
        lastPage: 1,
        from: 1,
        to: 1,
        hasMorePages: false,
    );

    $this->mock(EventSearchService::class, function (MockInterface $mock) use ($page): void {
        $mock->shouldReceive('search')->once()->andReturn($page);
        $mock->shouldReceive('searchNearbyWithQuery')->once()->andReturn($page);
    });

    $service = app(PublicScheduleDiscoveryService::class);

    $fallback = $service->search(
        query: 'Stale Fallback Private Programme',
        filters: ['time_scope' => 'all'],
    );

    expect($fallback->total())->toBe(0);

    $nearby = $service->search(
        query: 'Stale Fallback Private Programme',
        filters: ['time_scope' => 'all'],
        latitude: 3.139,
        longitude: 101.6869,
        radiusKm: 15,
    );

    expect($nearby->total())->toBe(0);
});

it('drops current non-public ids at final schedule hydration', function (): void {
    $public = Event::factory()->create([
        'title' => 'Current Public Hydration Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $private = Event::factory()->create([
        'title' => 'Private Hydration Programme',
        'status' => 'approved',
        'visibility' => 'private',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $unpublished = Event::factory()->create([
        'title' => 'Unpublished Hydration Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);
    $unpublished->updateQuietly(['published_at' => null]);

    $hydrated = app(PostgresEventDiscovery::class)->hydrateScheduleEvents([
        (string) $private->getKey(),
        (string) $unpublished->getKey(),
        (string) $public->getKey(),
    ]);

    expect($hydrated->pluck('id')->all())->toBe([(string) $public->getKey()]);
});

it('revalidates public state between fact selection and final hydration', function (): void {
    $event = Event::factory()->create([
        'title' => 'Hydration Race Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $readers = app(EventScheduleReads::class);
    $facts = $readers->publicScheduleFactSet('Hydration Race Programme', ['time_scope' => 'all']);

    expect($facts->eventIds)->toContain((string) $event->getKey());

    $event->updateQuietly(['visibility' => 'private']);

    expect(app(PostgresEventDiscovery::class)->hydrateScheduleEvents($facts->eventIds))->toBeEmpty();
});
