<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\EventKeyPersonRole;
use App\Shared\Directory\Enums\ReferenceType;
use App\Shared\Events\Enums\TimingMode;
use App\Livewire\Pages\Persons\Show;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Venue;
use App\Shared\Prayer\PrayerOffset;
use App\Shared\Prayer\PrayerReference;
use App\Support\Location\AddressHierarchyFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

function linkPersonEvent(Person $person, Event $event): void
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

it('shows prayer-relative timing text on person page instead of absolute time', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_reference' => PrayerReference::Asr,
        'prayer_offset' => PrayerOffset::Immediately,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    linkPersonEvent($person, $event);

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSeeText('Selepas Asar')
        ->assertDontSeeText((string) $event->starts_at?->format('h:i A'));
});

it('renders full timing display in the viewer timezone from X-Timezone', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'timing_mode' => TimingMode::Absolute,
        'starts_at' => Carbon::parse('2026-02-18 01:30:00', 'UTC'),
    ]);

    $request = Request::create('/events/'.$event->getKey());
    $request->headers->set('X-Timezone', 'America/Los_Angeles');
    $this->app->instance('request', $request);

    $originalLocale = app()->getLocale();
    app()->setLocale('en');

    try {
        $fullTimingDisplay = OwnerContext::withOwner(
            null,
            fn (): string => $event->fresh()->full_timing_display,
        );

        expect($fullTimingDisplay)
            ->toBe('Tuesday, 17 February 2026 - 5:30 PM');
    } finally {
        app()->setLocale($originalLocale);
    }
});

it('filters upcoming person events by friendly date ranges', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 30, 10, 0, 0, 'UTC'));

    $person = Person::factory()->create(['status' => 'verified']);

    $today = Event::factory()->create([
        'title' => 'Majlis Hari Ini',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => Carbon::create(2026, 7, 30, 12, 0, 0, 'UTC'),
    ]);
    $nextMonth = Event::factory()->create([
        'title' => 'Majlis Bulan Depan',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => Carbon::create(2026, 8, 3, 12, 0, 0, 'UTC'),
    ]);
    $thisMonth = Event::factory()->create([
        'title' => 'Majlis Bulan Ini',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => Carbon::create(2026, 7, 30, 14, 0, 0, 'UTC'),
    ]);

    linkPersonEvent($person, $today);
    linkPersonEvent($person, $nextMonth);
    linkPersonEvent($person, $thisMonth);

    try {
        Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->test(Show::class, ['person' => $person])
            ->set('upcomingDateFilter', 'today')
            ->assertSee('Majlis Hari Ini')
            ->assertDontSee('Majlis Bulan Depan')
            ->set('upcomingDateFilter', 'next_month')
            ->assertSee('Majlis Bulan Depan')
            ->assertDontSee('Majlis Hari Ini')
            ->set('upcomingDateFilter', 'this_month')
            ->assertSee('Majlis Hari Ini')
            ->assertSee('Majlis Bulan Ini')
            ->assertDontSee('Majlis Bulan Depan')
            ->set('upcomingDateFilter', 'tomorrow')
            ->assertSee('Tiada majlis untuk tempoh ini')
            ->assertSee('Tunjukkan semua majlis')
            ->set('customStartDate', '2026-08-01')
            ->set('customEndDate', '2026-08-31')
            ->call('applyCustomDateRange')
            ->assertSee('Majlis Bulan Depan')
            ->assertDontSee('Majlis Hari Ini');
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps hero event totals independent of the upcoming date filter', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 30, 10, 0, 0, 'UTC'));

    $person = Person::factory()->create(['status' => 'verified']);

    $today = Event::factory()->create([
        'title' => 'Majlis Hari Ini',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => Carbon::create(2026, 7, 30, 12, 0, 0, 'UTC'),
    ]);
    $nextMonth = Event::factory()->create([
        'title' => 'Majlis Bulan Depan',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => Carbon::create(2026, 8, 3, 12, 0, 0, 'UTC'),
    ]);
    $past = Event::factory()->create([
        'title' => 'Majlis Lepas',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => Carbon::create(2026, 7, 29, 12, 0, 0, 'UTC'),
    ]);

    linkPersonEvent($person, $today);
    linkPersonEvent($person, $nextMonth);
    linkPersonEvent($person, $past);

    try {
        Livewire::withCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->test(Show::class, ['person' => $person])
            ->assertSeeHtml('data-testid="hero-upcoming-total">2</p>')
            ->assertSeeHtml('data-testid="hero-past-total">1</p>')
            ->set('upcomingDateFilter', 'today')
            ->assertSee('Majlis Hari Ini')
            ->assertDontSee('Majlis Bulan Depan')
            ->assertSeeHtml('data-testid="hero-upcoming-total">2</p>')
            ->assertSeeHtml('data-testid="hero-past-total">1</p>')
            ->set('upcomingDateFilter', 'tomorrow')
            ->assertSee('Tiada majlis untuk tempoh ini')
            ->assertSeeHtml('data-testid="hero-upcoming-total">2</p>')
            ->assertSeeHtml('data-testid="hero-past-total">1</p>');
    } finally {
        Carbon::setTestNow();
    }
});

it('links to the filtered past events listing instead of rendering the speaker archive', function () {
    $person = Person::factory()->create(['status' => 'verified']);

    $upcoming = Event::factory()->create([
        'title' => 'Kuliah Penceramah Akan Datang',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
    ]);
    linkPersonEvent($person, $upcoming);

    $past = Event::factory()->create([
        'title' => 'Kuliah Penceramah Lepas',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->subDays(3),
    ]);
    linkPersonEvent($person, $past);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Kuliah Penceramah Akan Datang')
        ->assertDontSee('Kuliah Penceramah Lepas')
        ->assertSeeHtml('data-testid="person-past-events-cta"')
        ->assertSee(route('events.index', [
            'person_ids' => [(string) $person->getKey()],
            'time_scope' => 'past',
            'filters_panel' => 'closed',
        ]));
});

it('uses the localized tarawih label instead of the generic isha offset text', function () {
    $originalLocale = app()->getLocale();
    app()->setLocale('en');

    try {
        $person = Person::factory()->create([
            'status' => 'verified',
        ]);

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'starts_at' => now()->addDay()->setTime(21, 30),
            'ends_at' => now()->addDay()->setTime(23, 0),
            'timing_mode' => TimingMode::PrayerRelative,
            'prayer_reference' => PrayerReference::Isha,
            'prayer_offset' => PrayerOffset::After60,
            'prayer_display_text' => 'Selepas Tarawih',
        ]);

        linkPersonEvent($person, $event);

        $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->get(route('persons.show', $person))
            ->assertSuccessful()
            ->assertSeeText('After Tarawih')
            ->assertDontSeeText('1 hour after Isha');
    } finally {
        app()->setLocale($originalLocale);
    }
});

it('shows cancelled public events with cancelled badge on person page', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'cancelled',
        'visibility' => 'public',
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
    ]);

    linkPersonEvent($person, $event);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee($event->title)
        ->assertSee('Dibatalkan');
});

it('shows pending public events without a pending status badge or notice', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'pending',
        'visibility' => 'public',
        'starts_at' => now()->addDay()->setTime(17, 45),
    ]);

    linkPersonEvent($person, $event);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee($event->title)
        ->assertDontSee('Menunggu Kelulusan')
        ->assertDontSee('Semak lencana status pada setiap majlis sebelum hadir.');
});

it('uses the shared majlis card design on person page', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'delivery_mode' => EventFormat::Physical,
        'starts_at' => now()->addDays(3),
        'title' => 'Kuliah Kalender Penceramah',
    ]);

    linkPersonEvent($person, $event);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('data-testid="event-card-date-badge"', false)
        ->assertSee('data-testid="event-card-location"', false)
        ->assertSee('rounded-2xl border border-emerald-200', false);
});

it('renders event end time in event timezone on person page', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => now()->addDays(3)->setTime(9, 0)->utc(),
        'ends_at' => now()->addDays(3)->setTime(12, 40)->utc(),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_reference' => PrayerReference::Asr,
        'prayer_offset' => PrayerOffset::Immediately,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    linkPersonEvent($person, $event);

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSeeText('Selepas Asar')
        ->assertDontSeeText('12:40 PM');
});

it('shows dedicated venue name for event location on person page when available', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al-Hidayah Test',
    ]);

    $venue = Venue::factory()->create([
        'name' => 'Dewan Utama Test',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'delivery_mode' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_reference' => PrayerReference::Asr,
        'prayer_offset' => PrayerOffset::Immediately,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    linkPersonEvent($person, $event);

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Dewan Utama Test');
});

it('falls back to institution name for event location on person page when venue is missing', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al-Hidayah Test',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'delivery_mode' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'default_venue_id' => null,
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_reference' => PrayerReference::Asr,
        'prayer_offset' => PrayerOffset::Immediately,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    linkPersonEvent($person, $event);

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Masjid Al-Hidayah Test');
});

it('formats federal-territory venue addresses with product state_id and no district slot', function () {
    $venue = Venue::factory()->create([
        'name' => 'Dewan Utama Test',
        'status' => 'verified',
    ]);

    $geo = createTestPackageGeography('Kuala Lumpur', 'Federal District Placeholder', 'Setiawangsa');
    // Federal territory product shape: no district; local area in admin_area_2.
    $address = syncPrimaryAddressForTest($venue, [
        ...$geo['address'],
        'area_assignments' => [
            'administrative_subdivision' => (string) $geo['subdistrict']->getKey(),
        ],
        'city' => 'Setiawangsa',
    ]);

    expect($address->state_id)->toBe((string) $geo['state']->getKey());

    $parts = AddressHierarchyFormatter::parts($address);
    expect($parts)->toBe(['Setiawangsa', 'Kuala Lumpur']);

    $eventLocation = implode(', ', array_filter([
        $venue->name,
        ...$parts,
    ]));

    expect($eventLocation)->toBe('Dewan Utama Test, Setiawangsa, Kuala Lumpur')
        ->and($eventLocation)->not->toBe('Dewan Utama Test, Kuala Lumpur, Kuala Lumpur');
});

it('shows the country in a person location when no regional selection exists', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
        'slug' => 'country-only-person',
    ]);
    $country = ensureTestMalaysiaCountry();
    $address = Address::query()->create([
        'country_id' => $country->getKey(),
        'country' => 'Malaysia',
        'country_code' => 'MY',
        'state_id' => null,
        'city_id' => null,
        'state' => null,
        'city' => null,
    ]);
    $person->attachAddress($address, type: 'primary', isPrimary: true);

    expect(AddressHierarchyFormatter::parts($person->primaryAddress()))
        ->toBe(['Malaysia']);

    $person->refresh();

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Malaysia');
});

it('deduplicates matching person subdistrict and district labels in the person location badge', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $geo = createTestPackageGeography('Pahang', 'Temerloh', 'Temerloh');
    syncPrimaryAddressForTest($person, $geo['address']);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Temerloh, Pahang')
        ->assertDontSee('Temerloh, Temerloh, Pahang');
});

it('renders person page when linked event has online format and no location address', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'delivery_mode' => EventFormat::Online,
        'institution_id' => null,
        'default_venue_id' => null,
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::Absolute,
    ]);

    linkPersonEvent($person, $event);

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee($event->title);
});

it('shows linked non-person roles in a separate section on the person page', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $personEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
        'title' => 'Kuliah Utama Penceramah',
    ]);

    $moderatedEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
        'title' => 'Forum Dengan Moderator',
    ]);

    $personEvent->keyPeople()->create([
        'involveable_type' => 'person',
        'involveable_id' => $person->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);

    $moderatedEvent->keyPeople()->create([
        'involveable_type' => 'person',
        'involveable_id' => $person->id,
        'role_code' => EventKeyPersonRole::Moderator->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);

    $response = $this->get(route('persons.show', $person));

    $response->assertSuccessful()
        ->assertSee('Kuliah Utama Penceramah')
        ->assertSee('Peranan Lain Dalam Majlis')
        ->assertSee('Moderator')
        ->assertSee('Forum Dengan Moderator')
        ->assertSee('Peranan: Moderator')
        ->assertSee('<div class="mt-5 flex flex-wrap justify-center gap-4 *:w-full md:*:w-[calc(50%_-_0.5rem)]">', false)
        ->assertSee('border-violet-200 bg-violet-50 text-violet-800', false)
        ->assertSee('data-testid="event-card-date-badge"', false);

    expect(substr_count((string) $response->getContent(), 'data-testid="person-role-card-speakers"'))->toBe(1);
});

it('renders the book title on person event cards without parentheses', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    $bookEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2)->setTime(19, 30),
        'title' => 'Kuliah Maghrib Kitab Penceramah',
    ]);

    $articleEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3)->setTime(19, 30),
        'title' => 'Kuliah Maghrib Artikel Penceramah',
    ]);

    $bookReference = Reference::factory()->create([
        'title' => 'Al-Hikam',
        'type' => ReferenceType::Book->value,
    ]);

    $articleReference = Reference::factory()->create([
        'title' => 'Artikel Dakwah Semasa',
        'type' => ReferenceType::Article->value,
    ]);

    $bookEvent->references()->attach($bookReference->id);
    $articleEvent->references()->attach($articleReference->id);

    linkPersonEvent($person, $bookEvent);
    linkPersonEvent($person, $articleEvent);

    $response = $this->get(route('persons.show', $person));
    $response->assertSuccessful();

    $html = $response->getContent();

    preg_match('/<article[^>]*wire:key="upcoming-occurrence-'.preg_quote((string) $bookEvent->occurrences()->firstOrFail()->getKey(), '/').'"[^>]*>.*?<\/article>/s', (string) $html, $bookMatches);
    preg_match('/<article[^>]*wire:key="upcoming-occurrence-'.preg_quote((string) $articleEvent->occurrences()->firstOrFail()->getKey(), '/').'"[^>]*>.*?<\/article>/s', (string) $html, $articleMatches);

    $bookEventCard = $bookMatches[0] ?? null;
    $articleEventCard = $articleMatches[0] ?? null;

    expect($bookEventCard)->not->toBeNull();
    expect($articleEventCard)->not->toBeNull();

    expect($bookEventCard)
        ->toContain('Kuliah Maghrib Kitab Penceramah')
        ->toContain('Al-Hikam')
        ->not->toContain('(Al-Hikam)')
        ->toContain('font-bold')
        ->toContain('line-clamp-2')
        ->toContain('data-testid="event-card-date-badge"');

    expect($articleEventCard)
        ->toContain('Kuliah Maghrib Artikel Penceramah')
        ->not->toContain('Al-Hikam')
        ->not->toContain('Artikel Dakwah Semasa');
});
