<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Venue;
use Illuminate\Support\Carbon;

class CalendarService
{
    /**
     * Generate Google Calendar URL for an event.
     */
    public function googleCalendarUrl(Event $event): string
    {
        [$startAt, $endAt] = $this->nextCalendarWindow($event);

        $params = [
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => $this->formatGoogleDates($startAt, $endAt),
            'details' => $this->formatDescription($event),
            'location' => $this->formatLocation($event),
        ];

        return 'https://calendar.google.com/calendar/render?'.http_build_query($params);
    }

    /**
     * Generate Outlook/Office 365 Calendar URL for an event.
     */
    public function outlookCalendarUrl(Event $event): string
    {
        [$startAt, $endAt] = $this->nextCalendarWindow($event);

        $params = [
            'path' => '/calendar/0/deeplink/compose',
            'rru' => 'addevent',
            'subject' => $event->title,
            'body' => $event->description_text,
            'location' => $this->formatLocation($event),
            'startdt' => $startAt?->toIso8601String(),
            'enddt' => $endAt?->toIso8601String(),
        ];

        return 'https://outlook.live.com/calendar/0/deeplink/compose?'.http_build_query($params);
    }

    /**
     * Generate Office 365 Calendar URL for an event.
     */
    public function office365CalendarUrl(Event $event): string
    {
        [$startAt, $endAt] = $this->nextCalendarWindow($event);

        $params = [
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $event->title,
            'body' => $event->description_text,
            'location' => $this->formatLocation($event),
            'startdt' => $startAt?->toIso8601String(),
            'enddt' => $endAt?->toIso8601String(),
        ];

        return 'https://outlook.office.com/calendar/0/deeplink/compose?'.http_build_query($params);
    }

    /**
     * Generate Yahoo Calendar URL for an event.
     */
    public function yahooCalendarUrl(Event $event): string
    {
        [$startAt, $endAt] = $this->nextCalendarWindow($event);

        $params = [
            'v' => '60',
            'title' => $event->title,
            'st' => $startAt?->format('Ymd\THis'),
            'et' => $endAt?->format('Ymd\THis'),
            'desc' => $event->description_text,
            'in_loc' => $this->formatLocation($event),
        ];

        return 'https://calendar.yahoo.com/?'.http_build_query($params);
    }

    /**
     * Generate ICS file content for an event.
     */
    public function generateIcs(Event $event): string
    {
        $uidDomain = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($uidDomain) || $uidDomain === '') {
            $uidDomain = 'localhost';
        }

        $dtstamp = now()->format('Ymd\THis\Z');
        $summary = $this->escapeIcs($event->title);
        $description = $this->escapeIcs($this->formatDescription($event));
        $location = $this->escapeIcs($this->formatLocation($event));
        $url = route('events.show', $event);

        $organizer = $event->institution instanceof Institution
            ? $event->institution->name
            : config('app.name');

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= 'PRODID:-//'.config('app.name')."//Events//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";

        foreach ($this->sessionWindowsForIcs($event) as $window) {
            $dtstart = $window['start']->copy()->setTimezone('UTC')->format('Ymd\THis\Z');
            $dtend = $window['end']->copy()->setTimezone('UTC')->format('Ymd\THis\Z');
            $uid = $event->id.'-'.$window['uid'].'@'.$uidDomain;

            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:{$uid}\r\n";
            $ics .= "DTSTAMP:{$dtstamp}\r\n";
            $ics .= "DTSTART:{$dtstart}\r\n";
            $ics .= "DTEND:{$dtend}\r\n";
            $ics .= "SUMMARY:{$summary}\r\n";
            $ics .= "DESCRIPTION:{$description}\r\n";
            $ics .= "LOCATION:{$location}\r\n";
            $ics .= "URL:{$url}\r\n";
            $ics .= "ORGANIZER;CN={$organizer}:MAILTO:".config('mail.from.address', 'noreply@example.com')."\r\n";
            $ics .= "STATUS:CONFIRMED\r\n";
            $ics .= "TRANSP:OPAQUE\r\n";
            $ics .= "BEGIN:VALARM\r\n";
            $ics .= "TRIGGER:-PT1H\r\n";
            $ics .= "ACTION:DISPLAY\r\n";
            $ics .= "DESCRIPTION:Reminder: {$summary}\r\n";
            $ics .= "END:VALARM\r\n";
            $ics .= "END:VEVENT\r\n";
        }

        return $ics."END:VCALENDAR\r\n";
    }

    /**
     * Format dates for Google Calendar (YYYYMMDDTHHMMSS/YYYYMMDDTHHMMSS).
     */
    protected function formatGoogleDates(?Carbon $startAt, ?Carbon $endAt): string
    {
        $start = $startAt?->copy()->setTimezone('UTC')->format('Ymd\THis\Z');
        $end = $endAt?->copy()->setTimezone('UTC')->format('Ymd\THis\Z');

        return "{$start}/{$end}";
    }

    /**
     * Format the event description with additional details.
     */
    protected function formatDescription(Event $event): string
    {
        $parts = [];

        if ($event->description_text !== '') {
            $parts[] = $event->description_text;
        }

        // Add prayer-relative timing info
        if ($event->isPrayerRelative() && $event->prayer_display_text) {
            $parts[] = '';
            $parts[] = "Waktu: {$event->prayer_display_text}";
        }

        // Add speakers
        if ($event->speakers->isNotEmpty()) {
            $speakerNames = $event->speakers->pluck('name')->join(', ');
            $parts[] = '';
            $parts[] = "Penceramah: {$speakerNames}";
        }

        // Add link to event page
        $parts[] = '';
        $parts[] = 'Maklumat lanjut: '.route('events.show', $event);

        return implode("\n", $parts);
    }

    private function defaultEndAt(?Carbon $startsAt, Carbon|string|null $endsAt = null): ?Carbon
    {
        $normalizedEndAt = $this->asCarbon($endsAt);

        if ($normalizedEndAt instanceof Carbon) {
            return $normalizedEndAt->copy();
        }

        if ($startsAt instanceof Carbon) {
            return $startsAt->copy()->addHours(2);
        }

        return null;
    }

    private function asCarbon(Carbon|string|null $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function nextCalendarWindow(Event $event): array
    {
        $windows = $this->eventWindows($event);
        $timezone = $event->timezone ?: config('app.timezone', 'UTC');
        $now = now($timezone);

        $nextWindow = collect($windows)
            ->first(fn (array $window): bool => $window['start']->copy()->setTimezone($timezone)->greaterThanOrEqualTo($now));

        if (! is_array($nextWindow)) {
            $nextWindow = $windows[count($windows) - 1] ?? null;
        }

        if (is_array($nextWindow)) {
            return [
                $nextWindow['start']->copy(),
                $nextWindow['end']->copy(),
            ];
        }

        $eventStartAt = $this->asCarbon($event->starts_at);

        if ($eventStartAt instanceof Carbon) {
            return [
                $eventStartAt->copy(),
                $this->defaultEndAt($eventStartAt, $event->ends_at),
            ];
        }

        return [null, null];
    }

    /**
     * @return array<int, array{uid: string, start: Carbon, end: Carbon}>
     */
    protected function sessionWindowsForIcs(Event $event): array
    {
        return $this->eventWindows($event);
    }

    /**
     * @return array<int, array{uid: string, start: Carbon, end: Carbon}>
     */
    protected function eventWindows(Event $event): array
    {
        $windows = [];

        if ($event->isParentProgram()) {
            $childEvents = $event->relationLoaded('childEvents')
                ? $event->childEvents
                : $event->childEvents()->orderBy('starts_at')->get();

            foreach ($childEvents as $childEvent) {
                $startAt = $this->asCarbon($childEvent->starts_at);

                if (! $startAt instanceof Carbon) {
                    continue;
                }

                $windows[] = [
                    'uid' => (string) $childEvent->id,
                    'start' => $startAt->copy(),
                    'end' => $this->defaultEndAt($startAt, $childEvent->ends_at) ?? $startAt->copy()->addHours(2),
                ];
            }

            if ($windows !== []) {
                return $windows;
            }
        }

        $startAt = $this->asCarbon($event->starts_at);

        if ($startAt instanceof Carbon) {
            $windows[] = [
                'uid' => 'event',
                'start' => $startAt,
                'end' => $this->defaultEndAt($startAt, $event->ends_at) ?? $startAt->copy()->addHours(2),
            ];
        }

        return $windows;
    }

    /**
     * Format the event location.
     */
    protected function formatLocation(Event $event): string
    {
        $parts = [];
        $venue = $event->venue;
        $institution = $event->institution;

        if ($venue instanceof Venue) {
            $parts[] = $venue->name;

            if ($venue->address_line1 !== '') {
                $parts[] = $venue->address_line1;
            }
        } elseif ($institution instanceof Institution) {
            $parts[] = $institution->name;

            if ($institution->address_line1 !== '') {
                $parts[] = $institution->address_line1;
            }
        }

        return implode(', ', $parts) ?: 'Online';
    }

    /**
     * Escape special characters for ICS format.
     */
    protected function escapeIcs(string $text): string
    {
        $text = str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $text);

        // Fold long lines (max 75 chars per line)
        return wordwrap($text, 73, "\r\n ", true);
    }

    /**
     * Get all calendar links for an event.
     *
     * @return array<string, string>
     */
    public function getAllCalendarLinks(Event $event): array
    {
        return [
            'google' => $this->googleCalendarUrl($event),
            'outlook' => $this->outlookCalendarUrl($event),
            'office365' => $this->office365CalendarUrl($event),
            'yahoo' => $this->yahooCalendarUrl($event),
            'ics' => route('events.calendar', $event),
        ];
    }
}
