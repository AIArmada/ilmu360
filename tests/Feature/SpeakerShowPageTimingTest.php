<?php

use App\Enums\EventFormat;
use App\Enums\EventKeyPersonRole;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\ReferenceType;
use App\Enums\TimingMode;
use App\Models\District;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Venue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

it('hides state when district is kuala lumpur putrajaya or labuan', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $institution = Institution::factory()->create([
        'name' => 'Masjid Al-Hidayah Test',
    ]);

    $venue = Venue::factory()->create([
        'name' => 'Dewan Utama Test',
    ]);

    $stateId = DB::table('states')->insertGetId([
        'country_id' => 132,
        'name' => 'Kuala Lumpur',
        'country_code' => 'MY',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => 132,
        'state_id' => (int) $stateId,
        'district_id' => null,
        'country_code' => 'MY',
        'name' => 'Setiawangsa',
    ]);

    $venue->address()->update([
        'state_id' => (int) $stateId,
        'district_id' => null,
        'subdistrict_id' => $subdistrict->id,
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
        ->assertSee('Dewan Utama Test, '.$subdistrict->name.', Kuala Lumpur')
        ->assertDontSee('Dewan Utama Test, Kuala Lumpur, Kuala Lumpur');
});

it('deduplicates matching speaker subdistrict and district labels in the speaker location badge', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $state = State::query()->create([
        'country_id' => 132,
        'name' => 'Pahang',
        'country_code' => 'MY',
    ]);

    $district = District::query()->create([
        'country_id' => 132,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Temerloh',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => 132,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Temerloh',
    ]);

    $speaker->address()->update([
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'subdistrict_id' => (int) $subdistrict->id,
    ]);

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
        'is_active' => true,
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
        'is_active' => true,
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
