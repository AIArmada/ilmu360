<?php

use App\Enums\EventFormat;
use App\Enums\EventKeyPersonRole;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\ReferenceType;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\Venue;
use App\Support\Location\AddressHierarchyFormatter;
use Illuminate\Support\Carbon;

function linkSpeakerEvent(Speaker $speaker, Event $event): void
{
    EventKeyPerson::query()->create([
        'event_id' => $event->getKey(),
        'involveable_type' => 'speaker',
        'involveable_id' => $speaker->getKey(),
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);
}

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

    linkSpeakerEvent($speaker, $event);

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

        linkSpeakerEvent($speaker, $event);

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

    linkSpeakerEvent($speaker, $event);

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

    linkSpeakerEvent($speaker, $event);

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
        'delivery_mode' => EventFormat::Physical,
        'starts_at' => now()->addDays(3),
        'title' => 'Kuliah Kalender Penceramah',
    ]);

    linkSpeakerEvent($speaker, $event);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('from-emerald-700 to-emerald-950', false)
        ->assertSee('hover:border-emerald-300', false);
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

    linkSpeakerEvent($speaker, $event);

    $this->withUnencryptedCookie('user_timezone', 'Asia/Kuala_Lumpur')
        ->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSeeText('Selepas Asar')
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
        'delivery_mode' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'default_venue_id' => $venue->id,
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    linkSpeakerEvent($speaker, $event);

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
        'delivery_mode' => EventFormat::Physical,
        'institution_id' => $institution->id,
        'default_venue_id' => null,
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_display_text' => 'Selepas Asar',
    ]);

    linkSpeakerEvent($speaker, $event);

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
    expect($parts)->toBe(['Setiawangsa', 'Kuala Lumpur']);

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
        'delivery_mode' => EventFormat::Online,
        'institution_id' => null,
        'default_venue_id' => null,
        'starts_at' => now()->addDay()->setTime(17, 45),
        'ends_at' => now()->addDay()->setTime(19, 15),
        'timing_mode' => TimingMode::Absolute,
    ]);

    linkSpeakerEvent($speaker, $event);

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
        'involveable_type' => 'speaker',
        'involveable_id' => $speaker->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);

    $moderatedEvent->keyPeople()->create([
        'involveable_type' => 'speaker',
        'involveable_id' => $speaker->id,
        'role_code' => EventKeyPersonRole::Moderator->value,
        'sort_order' => 1,
        'visibility' => 'public',
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

    linkSpeakerEvent($speaker, $bookEvent);
    linkSpeakerEvent($speaker, $articleEvent);

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
        ->toContain('sm:grid-cols-[7.5rem_minmax(0,1fr)]');

    expect($articleEventCard)
        ->toContain('Kuliah Maghrib Artikel Penceramah')
        ->not->toContain('Al-Hikam')
        ->not->toContain('Artikel Dakwah Semasa');
});
