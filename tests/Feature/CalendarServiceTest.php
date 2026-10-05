<?php

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventSession;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Venue;
use App\Services\CalendarService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->calendarService = new CalendarService;
});

describe('CalendarService', function () {
    it('generates Google Calendar URL', function () {
        $event = Event::factory()->create([
            'title' => 'Kuliah Maghrib: Tafsir Al-Kahfi',
            'starts_at' => now()->addDays(7),
            'ends_at' => now()->addDays(7)->addHours(2),
        ]);

        $url = $this->calendarService->googleCalendarUrl($event);

        expect($url)->toStartWith('https://calendar.google.com/calendar/render?');
        expect($url)->toContain('action=TEMPLATE');
        expect($url)->toContain('text=Kuliah+Maghrib');
    });

    it('generates Outlook Calendar URL', function () {
        $event = Event::factory()->create([
            'title' => 'Forum Perdana',
            'starts_at' => now()->addDays(3),
        ]);

        $url = $this->calendarService->outlookCalendarUrl($event);

        expect($url)->toStartWith('https://outlook.live.com/calendar/');
        expect($url)->toContain('subject=Forum+Perdana');
    });

    it('generates Office 365 Calendar URL', function () {
        $event = Event::factory()->create([
            'title' => 'Halaqah Al-Quran',
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(5)->addHours(2),
        ]);

        $url = $this->calendarService->office365CalendarUrl($event);

        expect($url)->toStartWith('https://outlook.office.com/calendar/');
    });

    it('generates Yahoo Calendar URL', function () {
        $event = Event::factory()->create([
            'title' => 'Tazkirah Subuh',
            'starts_at' => now()->addDays(2),
        ]);

        $url = $this->calendarService->yahooCalendarUrl($event);

        expect($url)->toStartWith('https://calendar.yahoo.com/?');
        expect($url)->toContain('title=Tazkirah+Subuh');
    });

    it('generates valid ICS content', function () {
        $event = Event::factory()->create([
            'title' => 'Kuliah Isya: Hadis Arba\'in',
            'description' => 'Pengajian bersama ustaz',
            'starts_at' => now()->addDays(7)->setTime(21, 0),
            'ends_at' => now()->addDays(7)->setTime(23, 0),
        ]);

        $ics = $this->calendarService->generateIcs($event);

        expect($ics)->toContain('BEGIN:VCALENDAR');
        expect($ics)->toContain('VERSION:2.0');
        expect($ics)->toContain('BEGIN:VEVENT');
        expect($ics)->toContain('SUMMARY:Kuliah Isya');
        expect($ics)->toContain('DESCRIPTION:');
        expect($ics)->toContain('END:VEVENT');
        expect($ics)->toContain('END:VCALENDAR');
        // Check for alarm
        expect($ics)->toContain('BEGIN:VALARM');
        expect($ics)->toContain('TRIGGER:-PT1H');
    });

    it('generates one VEVENT per session in an event occurrence', function () {
        $event = Event::factory()->create([
            'title' => 'Siri Tafsir Mingguan',
            'starts_at' => now()->addDays(1)->setTime(20, 0),
            'ends_at' => now()->addDays(14)->setTime(22, 0),
        ]);

        $occurrence = $event->primaryOccurrence;
        $childA = EventSession::query()->create(['event_id' => $event->id, 'event_occurrence_id' => $occurrence->id, 'title' => 'Tafsir Mingguan 1', 'slug' => 'tafsir-mingguan-1', 'starts_at' => now()->addDays(1)->setTime(20, 0), 'ends_at' => now()->addDays(1)->setTime(22, 0), 'timezone' => $event->timezone, 'status' => 'scheduled', 'visibility' => 'public', 'delivery_mode' => 'in_person', 'sort_order' => 1]);
        $childB = EventSession::query()->create(['event_id' => $event->id, 'event_occurrence_id' => $occurrence->id, 'title' => 'Tafsir Mingguan 2', 'slug' => 'tafsir-mingguan-2', 'starts_at' => now()->addDays(8)->setTime(20, 0), 'ends_at' => now()->addDays(8)->setTime(22, 0), 'timezone' => $event->timezone, 'status' => 'scheduled', 'visibility' => 'public', 'delivery_mode' => 'in_person', 'sort_order' => 2]);

        $ics = $this->calendarService->generateIcs($event);

        expect(substr_count($ics, 'BEGIN:VEVENT'))->toBe(2);
        expect($ics)->toContain($childA->starts_at?->copy()->setTimezone('UTC')->format('Ymd\THis\Z'));
        expect($ics)->toContain($childB->starts_at?->copy()->setTimezone('UTC')->format('Ymd\THis\Z'));
    });

    it('uses the latest past session for event calendar deep links when no session is upcoming', function () {
        $event = Event::factory()->create([
            'title' => 'Siri Tazkirah Mingguan',
            'starts_at' => now()->subDays(10)->setTime(20, 0),
            'ends_at' => now()->subDays(10)->setTime(22, 0),
        ]);

        $occurrence = $event->primaryOccurrence;
        $olderChild = EventSession::query()->create(['event_id' => $event->id, 'event_occurrence_id' => $occurrence->id, 'title' => 'Older Session', 'slug' => 'older-session', 'starts_at' => now()->subDays(10)->setTime(20, 0), 'ends_at' => now()->subDays(10)->setTime(22, 0), 'timezone' => $event->timezone, 'status' => 'scheduled', 'visibility' => 'public', 'delivery_mode' => 'in_person', 'sort_order' => 1]);
        $latestPastChild = EventSession::query()->create(['event_id' => $event->id, 'event_occurrence_id' => $occurrence->id, 'title' => 'Latest Session', 'slug' => 'latest-session', 'starts_at' => now()->subDay()->setTime(20, 0), 'ends_at' => now()->subDay()->setTime(22, 0), 'timezone' => $event->timezone, 'status' => 'scheduled', 'visibility' => 'public', 'delivery_mode' => 'in_person', 'sort_order' => 2]);

        $url = $this->calendarService->googleCalendarUrl($event);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $dates = (string) ($query['dates'] ?? '');

        expect($dates)->toContain($latestPastChild->starts_at?->copy()->setTimezone('UTC')->format('Ymd\THis\Z'))
            ->and($dates)->not->toContain($olderChild->starts_at?->copy()->setTimezone('UTC')->format('Ymd\THis\Z'));
    });

    it('includes venue location in calendar events', function () {
        $venue = Venue::factory()->create([
            'name' => 'Masjid Negara',
        ]);
        syncPrimaryAddressForTest($venue, [
            'line1' => 'Jalan Perdana, KL',
        ]);

        $event = Event::factory()->create([
            'default_venue_id' => $venue->id,
            'starts_at' => now()->addDays(5),
        ]);

        $url = $this->calendarService->googleCalendarUrl($event);

        expect($url)->toContain('Masjid+Negara');
    });

    it('falls back to institution location when no venue', function () {
        $institution = Institution::factory()->create([
            'name' => 'Masjid Jamek',
        ]);
        syncPrimaryAddressForTest($institution, [
            'line1' => 'Jalan Tun Perak',
        ]);

        $event = Event::factory()->create([
            'institution_id' => $institution->id,
            'default_venue_id' => null,
            'starts_at' => now()->addDays(3),
        ]);

        $url = $this->calendarService->googleCalendarUrl($event);

        expect($url)->toContain('Masjid+Jamek');
    });

    it('returns all calendar links', function () {
        $event = Event::factory()->create([
            'starts_at' => now()->addDays(7),
        ]);

        $links = $this->calendarService->getAllCalendarLinks($event);

        expect($links)->toHaveKeys(['google', 'outlook', 'office365', 'yahoo', 'ics']);
        expect($links['google'])->toStartWith('https://calendar.google.com');
        expect($links['outlook'])->toStartWith('https://outlook.live.com');
        expect($links['office365'])->toStartWith('https://outlook.office.com');
        expect($links['yahoo'])->toStartWith('https://calendar.yahoo.com');
        expect($links['ics'])->toContain('/kalendar.ics');
    });

    it('builds occurrence links from the date window with a scoped download', function () {
        $event = Event::factory()->create([
            'title' => 'Kuliah Induk Kalendar',
            'starts_at' => now()->addDays(1),
        ]);

        $occurrence = $event->primaryOccurrence;
        $occurrence->update([
            'title' => 'Siri Kedua Kalendar',
            'slug' => 'siri-kedua-kalendar',
            'starts_at' => now()->addDays(9)->setTime(20, 0),
            'ends_at' => now()->addDays(9)->setTime(22, 0),
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $links = $this->calendarService->getAllCalendarLinksFor($event, $occurrence->fresh());

        expect($links)->toHaveKeys(['google', 'outlook', 'office365', 'yahoo', 'ics']);
        expect($links['google'])->toContain('Siri+Kedua+Kalendar');
        expect($links['google'])->toContain($occurrence->starts_at->copy()->setTimezone('UTC')->format('Ymd\THis\Z'));
        expect($links['ics'])->toContain('/siri-kedua-kalendar/kalendar.ics');
    });

    it('builds session links from the session window with a scoped download', function () {
        $event = Event::factory()->create([
            'title' => 'Kuliah Induk Sesi',
            'starts_at' => now()->addDays(1),
        ]);

        $occurrence = $event->primaryOccurrence;
        $occurrence->update([
            'title' => 'Siri Sesi Kalendar',
            'slug' => 'siri-sesi-kalendar',
            'starts_at' => now()->addDays(9)->setTime(20, 0),
            'ends_at' => now()->addDays(9)->setTime(22, 0),
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $session = EventSession::query()->create([
            'event_id' => $event->id,
            'event_occurrence_id' => $occurrence->id,
            'title' => 'Sesi Soal Jawab Kalendar',
            'slug' => 'sesi-soal-jawab-kalendar',
            'starts_at' => now()->addDays(9)->setTime(20, 30),
            'ends_at' => now()->addDays(9)->setTime(21, 30),
            'timezone' => $event->timezone,
            'status' => 'published',
            'visibility' => 'public',
            'delivery_mode' => 'in_person',
            'sort_order' => 1,
        ]);

        $links = $this->calendarService->getAllCalendarLinksFor($event, $session, $occurrence);

        expect($links['google'])->toContain('Sesi+Soal+Jawab+Kalendar');
        expect($links['google'])->toContain($session->starts_at->copy()->setTimezone('UTC')->format('Ymd\THis\Z'));
        expect($links['ics'])->toContain('/siri-sesi-kalendar/sesi-soal-jawab-kalendar/kalendar.ics');
    });

    it('preserves an explicit null end instead of inventing a duration', function () {
        $event = Event::factory()->create([
            'title' => 'Kuliah Terbuka Kalendar',
            'starts_at' => now()->addDays(1),
        ]);

        $occurrence = $event->primaryOccurrence;
        $occurrence->update([
            'title' => 'Siri Terbuka Kalendar',
            'slug' => 'siri-terbuka-kalendar',
            'starts_at' => now()->addDays(9)->setTime(20, 0),
            'ends_at' => null,
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $links = $this->calendarService->getAllCalendarLinksFor($event, $occurrence->fresh());

        parse_str((string) parse_url($links['google'], PHP_URL_QUERY), $query);

        expect((string) ($query['dates'] ?? ''))->toEndWith('/');

        $ics = $this->calendarService->generateIcsFor($event, $occurrence->fresh());

        expect($ics)->toContain('BEGIN:VEVENT');
        expect($ics)->not->toContain('DTEND:');
    });

    it('excludes private schedule windows from the exported feed', function () {
        $event = Event::factory()->create([
            'title' => 'Siri Peribadi Kalendar',
            'starts_at' => now()->addDays(1)->setTime(20, 0),
            'ends_at' => now()->addDays(14)->setTime(22, 0),
        ]);

        $occurrence = $event->primaryOccurrence;
        $occurrence->update(['status' => 'published', 'visibility' => 'public']);

        EventSession::query()->create([
            'event_id' => $event->id,
            'event_occurrence_id' => $occurrence->id,
            'title' => 'Sesi Awam Kalendar',
            'slug' => 'sesi-awam-kalendar',
            'starts_at' => now()->addDays(2)->setTime(20, 0),
            'ends_at' => now()->addDays(2)->setTime(22, 0),
            'timezone' => $event->timezone,
            'status' => 'published',
            'visibility' => 'public',
            'delivery_mode' => 'in_person',
            'sort_order' => 1,
        ]);

        $hiddenStart = now()->addDays(3)->setTime(20, 0);

        EventSession::query()->create([
            'event_id' => $event->id,
            'event_occurrence_id' => $occurrence->id,
            'title' => 'Sesi Draf Sulit',
            'slug' => 'sesi-draf-sulit',
            'starts_at' => $hiddenStart,
            'ends_at' => $hiddenStart->copy()->addHours(2),
            'timezone' => $event->timezone,
            'status' => 'draft',
            'visibility' => 'public',
            'delivery_mode' => 'in_person',
            'sort_order' => 2,
        ]);

        $ics = $this->calendarService->generateIcs($event->fresh());

        expect(substr_count($ics, 'BEGIN:VEVENT'))->toBe(1);
        expect($ics)->not->toContain($hiddenStart->copy()->setTimezone('UTC')->format('Ymd\THis\Z'));
    });

    it('exports no dates when every occurrence is hidden', function (
        string $status,
        string $visibility,
        array $relations,
    ): void {
        $event = Event::factory()->create([
            'title' => 'Hidden Calendar Schedule',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'timezone' => 'UTC',
            'timing_mode' => 'absolute',
            'starts_at' => Carbon::parse('2030-01-02 20:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2030-01-02 22:00:00', 'UTC'),
        ]);
        $event->primaryOccurrence->update([
            'status' => $status,
            'visibility' => $visibility,
        ]);
        $event = $event->fresh($relations);

        $ics = $this->calendarService->generateIcs($event);
        parse_str(
            (string) parse_url($this->calendarService->googleCalendarUrl($event), PHP_URL_QUERY),
            $query,
        );

        expect($ics)->not->toContain('BEGIN:VEVENT');
        expect($query['dates'])->toBe('/');

        $response = $this->get(route('events.calendar', $event));

        $response->assertOk();
        expect((string) $response->getContent())->not->toContain('BEGIN:VEVENT');
    })->with([
        'private, lazy' => ['published', 'private', []],
        'private, loaded' => ['published', 'private', ['occurrences.sessions']],
        'draft, lazy' => ['draft', 'public', []],
        'draft, loaded' => ['draft', 'public', ['occurrences.sessions']],
    ]);

    it('downloads a single date through its scoped calendar route', function () {
        $event = Event::factory()->create([
            'title' => 'Kuliah Muat Turun Kalendar',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDays(1),
        ]);

        $occurrence = $event->primaryOccurrence;
        $occurrence->update([
            'title' => 'Siri Muat Turun Kalendar',
            'slug' => 'siri-muat-turun-kalendar',
            'starts_at' => now()->addDays(9)->setTime(20, 0),
            'ends_at' => now()->addDays(9)->setTime(22, 0),
            'status' => 'published',
            'visibility' => 'public',
        ]);

        $response = $this->get(route('events.occurrence.calendar', [
            'event' => $event,
            'occurrenceSlug' => 'siri-muat-turun-kalendar',
        ]));

        $response->assertOk();
        expect((string) $response->getContent())->toContain('Siri Muat Turun Kalendar');
        expect(substr_count((string) $response->getContent(), 'BEGIN:VEVENT'))->toBe(1);
    });

    it('returns 404 for a non-public scoped calendar download', function () {
        $event = Event::factory()->create([
            'title' => 'Kuliah Sulit Kalendar',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'starts_at' => now()->addDays(1),
        ]);

        $occurrence = $event->primaryOccurrence;
        $occurrence->update([
            'title' => 'Siri Sulit Kalendar',
            'slug' => 'siri-sulit-kalendar',
            'starts_at' => now()->addDays(9)->setTime(20, 0),
            'status' => 'draft',
            'visibility' => 'public',
        ]);

        $this->get(route('events.occurrence.calendar', [
            'event' => $event,
            'occurrenceSlug' => 'siri-sulit-kalendar',
        ]))->assertNotFound();
    });

    it('rejects scoped calendars for cancelled or unconfirmed schedules', function (
        string $blockedScope,
        string $status,
    ): void {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'timing_mode' => 'absolute',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);
        $occurrence = $event->primaryOccurrence;
        $occurrence->update([
            'slug' => 'calendar-date',
            'status' => $blockedScope === 'occurrence' ? $status : 'published',
            'visibility' => 'public',
        ]);
        EventSession::query()->create([
            'event_id' => $event->id,
            'event_occurrence_id' => $occurrence->id,
            'title' => 'Calendar Session',
            'slug' => 'calendar-session',
            'starts_at' => $occurrence->starts_at,
            'ends_at' => $occurrence->ends_at,
            'timezone' => $event->timezone,
            'status' => $blockedScope === 'session' ? $status : 'published',
            'visibility' => 'public',
            'delivery_mode' => 'in_person',
            'sort_order' => 1,
        ]);
        $parameters = [
            'event' => $event,
            'occurrenceSlug' => 'calendar-date',
            'sessionSlug' => 'calendar-session',
        ];

        $this->get(route('events.session', $parameters))
            ->assertOk()
            ->assertDontSee(__('Tambah ke Kalendar'));

        $this->get(route('events.session.calendar', $parameters))
            ->assertNotFound();

        if ($blockedScope === 'occurrence') {
            $this->get(route('events.occurrence', [
                'event' => $event,
                'occurrenceSlug' => 'calendar-date',
            ]))
                ->assertOk()
                ->assertDontSee(__('Tambah ke Kalendar'));

            $this->get(route('events.occurrence.calendar', [
                'event' => $event,
                'occurrenceSlug' => 'calendar-date',
            ]))->assertNotFound();
        }
    })->with([
        'postponed occurrence' => ['occurrence', 'postponed'],
        'rescheduled occurrence' => ['occurrence', 'rescheduled'],
        'postponed session' => ['session', 'postponed'],
        'rescheduled session' => ['session', 'rescheduled'],
        'cancelled occurrence' => ['occurrence', 'cancelled'],
        'cancelled session' => ['session', 'cancelled'],
    ]);

    it('keeps the room name in scoped calendar locations', function (): void {
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'timing_mode' => 'absolute',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);
        $occurrence = $event->primaryOccurrence;
        $occurrence->update([
            'slug' => 'calendar-room-date',
            'status' => 'published',
            'visibility' => 'public',
        ]);
        EventLocation::query()->create([
            'event_id' => $event->id,
            'event_occurrence_id' => $occurrence->id,
            'location_role' => 'primary',
            'space_name_snapshot' => 'Bilik Kuliah Ar-Rahman',
            'status' => 'active',
            'visibility' => 'public',
        ]);

        $content = (string) $this->get(route('events.occurrence.calendar', [
            'event' => $event,
            'occurrenceSlug' => 'calendar-room-date',
        ]))->assertOk()->getContent();

        expect($content)->toContain('Bilik Kuliah Ar-Rahman');
    });

    it('uses the scoped institution instead of the parent venue in session calendars', function (): void {
        $venue = Venue::factory()->create([
            'name' => 'Parent Calendar Venue',
        ]);
        $institution = Institution::factory()->create([
            'name' => 'Scoped Calendar Institution',
        ]);
        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'default_venue_id' => $venue->getKey(),
            'delivery_mode' => 'physical',
            'timing_mode' => 'absolute',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);
        $occurrence = $event->primaryOccurrence;
        $occurrence->update([
            'slug' => 'institution-calendar-date',
            'status' => 'published',
            'visibility' => 'public',
        ]);
        $session = EventSession::query()->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'title' => 'Institution Calendar Session',
            'slug' => 'institution-calendar-session',
            'starts_at' => $occurrence->starts_at,
            'ends_at' => $occurrence->ends_at,
            'timezone' => $event->timezone,
            'status' => 'published',
            'visibility' => 'public',
            'delivery_mode' => 'in_person',
            'sort_order' => 1,
        ]);
        $session->locations()->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'location_role' => 'primary',
            'locationable_type' => $institution->getMorphClass(),
            'locationable_id' => $institution->getKey(),
            'status' => 'active',
            'visibility' => 'public',
        ]);

        $session->load('locations');
        $links = $this->calendarService->getAllCalendarLinksFor(
            $event,
            $session,
            $occurrence,
        );
        parse_str(
            (string) parse_url($links['google'], PHP_URL_QUERY),
            $query,
        );

        expect($query['location'])->toBe('Scoped Calendar Institution');

        $this->get(route('events.session.calendar', [
            'event' => $event,
            'occurrenceSlug' => 'institution-calendar-date',
            'sessionSlug' => 'institution-calendar-session',
        ]))
            ->assertOk()
            ->assertSee('LOCATION:Scoped Calendar Institution', false)
            ->assertDontSee('Parent Calendar Venue');
    });
});
