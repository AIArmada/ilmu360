<?php

use AIArmada\Addressing\Models\State;
use App\Enums\EventFormat;
use App\Enums\EventKeyPersonRole;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\ReferenceType;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\Venue;
use App\Support\Location\AddressHierarchyFormatter;
use App\Support\Location\FederalTerritoryLocation;
use Illuminate\Support\Carbon;

it('shows prayer-relative timing text on speaker page instead of absolute time', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    $speaker->speakerEvents()->attach($event->id);

    $expectedEndTime = $event->ends_at?->copy()->timezone('Asia/Kuala_Lumpur')->format('h:i A');

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSeeText('Selepas Asar')
        ->assertSeeText((string) $expectedEndTime)
        ->assertDontSeeText((string) $event->starts_at?->format('h:i A'));
});

it('uses the localized tarawih label instead of the generic isha offset text', function () {
    $originalLocale = app()->getLocale();
    app()->setLocale('en');

    try {
        $speaker = Speaker::factory()->create([
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

        $speaker->speakerEvents()->attach($event->id);

        $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
            ->get(route('speakers.show', $speaker))
            ->assertSuccessful()
            ->assertSeeText('After Tarawih')
            ->assertDontSeeText('1 hour after Isha');
    } finally {
        app()->setLocale($originalLocale);
    }
});

it('shows cancelled public events with cancelled badge on speaker page', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'cancelled',
        'visibility' => 'public',
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
    ]);

    $speaker->speakerEvents()->attach($event->id);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee($event->title)
        ->assertSee('Dibatalkan');
});

it('shows a moderation note when speaker page lists pending public events', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'pending',
        'visibility' => 'public',
        'starts_at' => now()->addDay()->setTime(17, 45),
    ]);

    $speaker->speakerEvents()->attach($event->id);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee($event->title)
        ->assertSee('Menunggu Kelulusan')
        ->assertSee('Semak lencana status pada setiap majlis sebelum hadir.');
});

it('uses stronger calendar event colors on speaker page', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
        'title' => 'Kuliah Kalender Penceramah',
    ]);

    $speaker->speakerEvents()->attach($event->id);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('border-emerald-300 bg-emerald-100 text-emerald-900 shadow-emerald-200/80 hover:bg-emerald-200', false)
        ->assertDontSee('bg-emerald-50 text-emerald-700 hover:bg-emerald-100', false);
});

it('renders event end time in event timezone on speaker page', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'timezone' => 'Asia/Kuala_Lumpur',
        'starts_at' => Carbon::parse('2026-02-18 09:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-02-18 12:40:00', 'UTC'),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    $speaker->speakerEvents()->attach($event->id);

    $expectedEndTime = $event->ends_at?->copy()->timezone('Asia/Kuala_Lumpur')->format('h:i A');

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSeeText('Selepas Asar')
        ->assertSeeText((string) $expectedEndTime)
        ->assertDontSeeText('12:40 PM');
});

it('shows dedicated venue name for event location on speaker page when available', function () {
    $speaker = Speaker::factory()->create([
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
        'event_format' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'venue_id' => $venue->id,
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    $speaker->speakerEvents()->attach($event->id);

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('Dewan Utama Test');
});

it('falls back to institution name for event location on speaker page when venue is missing', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al-Hidayah Test',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'event_format' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'venue_id' => null,
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    $speaker->speakerEvents()->attach($event->id);

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('speakers.show', $speaker))
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
        'admin_area_1_id' => null,
        'admin_area_2_id' => (string) $geo['subdistrict']->getKey(),
        'city' => 'Setiawangsa',
    ]);

    expect($address->state_id)->toBe((string) $geo['state']->getKey())
        ->and($address->admin_area_1_id)->toBeNull()
        ->and($address->admin_area_2_id)->toBe((string) $geo['subdistrict']->getKey());

    $parts = AddressHierarchyFormatter::parts($address);
    // State label is suppressed for FT names inside the formatter.
    expect($parts)->toBe(['Setiawangsa']);

    // Speaker event-location UI re-appends FT state once when only a single local part remains.
    $stateName = State::query()->whereKey($address->state_id)->value('name');
    if (count($parts) === 1 && FederalTerritoryLocation::isFederalTerritoryStateName($stateName)) {
        $parts[] = $stateName;
    }

    $eventLocation = implode(', ', array_filter([
        $venue->name,
        ...$parts,
    ]));

    expect($eventLocation)->toBe('Dewan Utama Test, Setiawangsa, Kuala Lumpur')
        ->and($eventLocation)->not->toBe('Dewan Utama Test, Kuala Lumpur, Kuala Lumpur');
});

it('deduplicates matching speaker subdistrict and district labels in the speaker location badge', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $geo = createTestPackageGeography('Pahang', 'Temerloh', 'Temerloh');
    syncPrimaryAddressForTest($speaker, $geo['address']);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('Temerloh, Pahang')
        ->assertDontSee('Temerloh, Temerloh, Pahang');
});

it('renders speaker page when linked event has online format and no location address', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'event_format' => EventFormat::Online,
        'institution_id' => null,
        'venue_id' => null,
        'space_id' => null,
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::Absolute,
    ]);

    $speaker->speakerEvents()->attach($event->id);

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee($event->title);
});

it('shows linked non-speaker roles in a separate section on the speaker page', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $speakerEvent = Event::factory()->create([
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

    $speakerEvent->keyPeople()->create([
        'speaker_id' => $speaker->id,
        'role' => EventKeyPersonRole::Speaker,
        'order_column' => 1,
        'is_public' => true,
    ]);

    $moderatedEvent->keyPeople()->create([
        'speaker_id' => $speaker->id,
        'role' => EventKeyPersonRole::Moderator,
        'order_column' => 1,
        'is_public' => true,
    ]);

    $response = $this->get(route('speakers.show', $speaker));

    $response->assertSuccessful()
        ->assertSee('Kuliah Utama Penceramah')
        ->assertSee('Peranan Lain Dalam Majlis')
        ->assertSee('Moderator')
        ->assertSee('Forum Dengan Moderator');

    expect(substr_count((string) $response->getContent(), 'Forum Dengan Moderator'))->toBe(1);
});

it('renders the book title on speaker event cards without parentheses', function () {
    $speaker = Speaker::factory()->create([
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

    $speaker->speakerEvents()->attach([$bookEvent->id, $articleEvent->id]);

    $response = $this->get(route('speakers.show', $speaker));
    $response->assertSuccessful();

    $html = $response->getContent();

    preg_match('/<a[^>]*wire:key="upcoming-'.preg_quote($bookEvent->id, '/').'"[^>]*>.*?<\/a>/s', (string) $html, $bookMatches);
    preg_match('/<a[^>]*wire:key="upcoming-'.preg_quote($articleEvent->id, '/').'"[^>]*>.*?<\/a>/s', (string) $html, $articleMatches);

    $bookEventCard = $bookMatches[0] ?? null;
    $articleEventCard = $articleMatches[0] ?? null;

    expect($bookEventCard)->not->toBeNull();
    expect($articleEventCard)->not->toBeNull();

    expect($bookEventCard)
        ->toContain('Kuliah Maghrib Kitab Penceramah')
        ->toContain('Al-Hikam')
        ->not->toContain('(Al-Hikam)')
        ->toContain('font-bold')
        ->toContain('italic')
        ->toContain('sm:pl-4');

    expect($articleEventCard)
        ->toContain('Kuliah Maghrib Artikel Penceramah')
        ->not->toContain('Al-Hikam')
        ->not->toContain('Artikel Dakwah Semasa');
});
