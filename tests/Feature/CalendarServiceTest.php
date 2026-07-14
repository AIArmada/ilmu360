<?php

use AIArmada\Events\Models\EventSession;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Venue;
use App\Services\CalendarService;

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
});
