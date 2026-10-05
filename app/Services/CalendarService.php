<?php

declare(strict_types=1);

namespace App\Services;

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Venue;
use App\Support\Events\PublicSchedulePolicy;
use App\Support\Events\PublicScheduleSlug;
use App\Support\Spaces\SpaceLocationPresenter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

class CalendarService
{
    /**
     * Generate Google Calendar URL for an event.
     */
    public function googleCalendarUrl(Event $event): string
    {
        [$startAt, $endAt] = $this->nextCalendarWindow($event);

        return $this->googleUrl(
            $event->title,
            $startAt,
            $endAt,
            $this->formatDescription($event),
            $this->formatLocation($event),
        );
    }

    /**
     * Generate Outlook/Office 365 Calendar URL for an event.
     */
    public function outlookCalendarUrl(Event $event): string
    {
        [$startAt, $endAt] = $this->nextCalendarWindow($event);

        return $this->outlookUrl(
            $event->title,
            $startAt,
            $endAt,
            (string) $event->description_text,
            $this->formatLocation($event),
        );
    }

    /**
     * Generate Office 365 Calendar URL for an event.
     */
    public function office365CalendarUrl(Event $event): string
    {
        [$startAt, $endAt] = $this->nextCalendarWindow($event);

        return $this->office365Url(
            $event->title,
            $startAt,
            $endAt,
            (string) $event->description_text,
            $this->formatLocation($event),
        );
    }

    /**
     * Generate Yahoo Calendar URL for an event.
     */
    public function yahooCalendarUrl(Event $event): string
    {
        [$startAt, $endAt] = $this->nextCalendarWindow($event);

        return $this->yahooUrl(
            $event->title,
            $startAt,
            $endAt,
            (string) $event->description_text,
            $this->formatLocation($event),
        );
    }

    /**
     * Generate ICS file content for an event. Only publicly reachable
     * schedule windows are exported; private/draft dates never leak into the
     * feed. Explicitly open-ended windows keep a null end (no DTEND).
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
            $dtstart = $window['start']->setTimezone('UTC')->format('Ymd\THis\Z');
            $uid = $event->id.'-'.$window['uid'].'@'.$uidDomain;

            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:{$uid}\r\n";
            $ics .= "DTSTAMP:{$dtstamp}\r\n";
            $ics .= "DTSTART:{$dtstart}\r\n";

            if ($window['end'] instanceof CarbonImmutable) {
                $dtend = $window['end']->setTimezone('UTC')->format('Ymd\THis\Z');
                $ics .= "DTEND:{$dtend}\r\n";
            }

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
     * Generate a single-scope ICS download: the selected date or session with
     * its own title, window, host location and canonical page URL.
     */
    public function generateIcsFor(Event $event, EventOccurrence|EventSession $scope, ?EventOccurrence $parentOccurrence = null): string
    {
        $this->assertPublicScope($scope);

        $window = $this->scopeWindow($event, $scope, $parentOccurrence);

        $uidDomain = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($uidDomain) || $uidDomain === '') {
            $uidDomain = 'localhost';
        }

        $dtstamp = now()->format('Ymd\THis\Z');
        $summary = $this->escapeIcs($window['title']);
        $description = $this->escapeIcs($window['description']);
        $location = $this->escapeIcs($window['location']);

        $organizer = $event->institution instanceof Institution
            ? $event->institution->name
            : config('app.name');

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= 'PRODID:-//'.config('app.name')."//Events//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$event->id}-{$scope->getKey()}@{$uidDomain}\r\n";
        $ics .= "DTSTAMP:{$dtstamp}\r\n";

        if ($window['start'] instanceof CarbonImmutable) {
            $ics .= 'DTSTART:'.$window['start']->setTimezone('UTC')->format('Ymd\THis\Z')."\r\n";
        }

        if ($window['end'] instanceof CarbonImmutable) {
            $ics .= 'DTEND:'.$window['end']->setTimezone('UTC')->format('Ymd\THis\Z')."\r\n";
        }

        $ics .= "SUMMARY:{$summary}\r\n";
        $ics .= "DESCRIPTION:{$description}\r\n";
        $ics .= "LOCATION:{$location}\r\n";
        $ics .= "URL:{$window['url']}\r\n";
        $ics .= "ORGANIZER;CN={$organizer}:MAILTO:".config('mail.from.address', 'noreply@example.com')."\r\n";
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "TRANSP:OPAQUE\r\n";
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-PT1H\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Reminder: {$summary}\r\n";
        $ics .= "END:VALARM\r\n";
        $ics .= "END:VEVENT\r\n";

        return $ics."END:VCALENDAR\r\n";
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

    /**
     * Canonical scope-specific calendar links for a date or session page. The
     * selected scope's own schedule, title, host and download route win; the
     * parent event is only a fallback for missing scope content.
     *
     * @return array<string, string>
     */
    public function getAllCalendarLinksFor(Event $event, EventOccurrence|EventSession $scope, ?EventOccurrence $parentOccurrence = null): array
    {
        $this->assertPublicScope($scope);

        $window = $this->scopeWindow($event, $scope, $parentOccurrence);

        return [
            'google' => $this->googleUrl($window['title'], $window['start'], $window['end'], $window['description'], $window['location']),
            'outlook' => $this->outlookUrl($window['title'], $window['start'], $window['end'], $window['description'], $window['location']),
            'office365' => $this->office365Url($window['title'], $window['start'], $window['end'], $window['description'], $window['location']),
            'yahoo' => $this->yahooUrl($window['title'], $window['start'], $window['end'], $window['description'], $window['location']),
            'ics' => $window['ics'],
        ];
    }

    /**
     * Resolve one scope's calendar content: own title/window/host first, with
     * explicit null ends preserved (never parent-substituted or invented).
     *
     * @return array{title: string, start: ?CarbonImmutable, end: ?CarbonImmutable, description: string, location: string, url: string, ics: string}
     */
    protected function scopeWindow(Event $event, EventOccurrence|EventSession $scope, ?EventOccurrence $parentOccurrence = null): array
    {
        $title = trim((string) $scope->title);

        if ($title === '') {
            $title = $parentOccurrence instanceof EventOccurrence && trim((string) $parentOccurrence->title) !== ''
                ? trim((string) $parentOccurrence->title)
                : $event->title;
        }

        // Occurrences have no description column; their calendar text always
        // falls back to the parent event description below.
        $ownDescription = $scope instanceof EventSession
            ? ($scope->description ?? $scope->summary)
            : null;

        $description = trim((string) $ownDescription) !== ''
            ? (string) $ownDescription
            : $this->formatDescription($event);

        if ($scope instanceof EventSession) {
            $occurrence = $parentOccurrence ?? $this->parentOccurrenceFor($scope);

            return [
                'title' => $title,
                'start' => $this->normalizeDate($scope->starts_at),
                'end' => $this->normalizeDate($scope->ends_at),
                'description' => $description,
                'location' => $this->formatLocationForScope($event, $scope, $occurrence),
                'url' => $occurrence instanceof EventOccurrence
                    ? route('events.session', ['event' => $event, 'occurrenceSlug' => PublicScheduleSlug::occurrence($occurrence), 'sessionSlug' => PublicScheduleSlug::session($scope)])
                    : route('events.show', $event),
                'ics' => $occurrence instanceof EventOccurrence
                    ? route('events.session.calendar', ['event' => $event, 'occurrenceSlug' => PublicScheduleSlug::occurrence($occurrence), 'sessionSlug' => PublicScheduleSlug::session($scope)])
                    : route('events.calendar', $event),
            ];
        }

        return [
            'title' => $title,
            'start' => $this->normalizeDate($scope->starts_at),
            'end' => $this->normalizeDate($scope->ends_at),
            'description' => $description,
            'location' => $this->formatLocationForScope($event, $scope),
            'url' => route('events.occurrence', ['event' => $event, 'occurrenceSlug' => PublicScheduleSlug::occurrence($scope)]),
            'ics' => route('events.occurrence.calendar', ['event' => $event, 'occurrenceSlug' => PublicScheduleSlug::occurrence($scope)]),
        ];
    }

    private function assertPublicScope(EventOccurrence|EventSession $scope): void
    {
        $public = $scope instanceof EventOccurrence
            ? PublicSchedulePolicy::isPublicOccurrence($scope)
            : PublicSchedulePolicy::isMeaningfulSession($scope);

        if (! $public) {
            throw new InvalidArgumentException('Calendar links are only available for publicly reachable schedule scopes.');
        }
    }

    private function parentOccurrenceFor(EventSession $session): ?EventOccurrence
    {
        if ($session->relationLoaded('occurrence')) {
            $occurrence = $session->getRelation('occurrence');

            return $occurrence instanceof EventOccurrence ? $occurrence : null;
        }

        if ($session->relationLoaded('eventOccurrence')) {
            $occurrence = $session->getRelation('eventOccurrence');

            return $occurrence instanceof EventOccurrence ? $occurrence : null;
        }

        return null;
    }

    /**
     * Scope host location: the scope's own primary location wins, then the
     * parent date's, then the event fallback. Uses loaded relations only.
     */
    protected function formatLocationForScope(Event $event, EventOccurrence|EventSession $scope, ?EventOccurrence $parentOccurrence = null): string
    {
        $location = $this->loadedPrimaryLocationFor($scope)
            ?? ($parentOccurrence instanceof EventOccurrence ? $this->loadedPrimaryLocationFor($parentOccurrence) : null);

        if ($location === null) {
            return $this->formatLocation($event);
        }

        $venueName = $location->relationLoaded('venue') ? $location->venue?->name : null;

        if (! is_string($venueName) || trim($venueName) === '') {
            $venueId = $location->getAttribute('venue_id');

            $venueName = is_string($venueId) && $venueId !== ''
                ? Venue::query()->whereKey($venueId)->value('name')
                : null;
        }

        if (! is_string($venueName) || trim($venueName) === '') {
            $location->loadMissing('locationable');
            $locationable = $location->getRelation('locationable');

            if ($locationable instanceof Institution) {
                $locationable->loadMissing('names');
                $venueName = $locationable->display_name;
            }
        }

        $label = trim((string) ($location->getAttribute('label') ?? ''));
        $spaceName = SpaceLocationPresenter::name($location);

        $parts = array_values(array_filter([
            is_string($venueName) && trim($venueName) !== '' ? trim($venueName) : null,
            $label !== '' ? $label : null,
            is_string($spaceName) && trim($spaceName) !== '' ? trim($spaceName) : null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        return $parts !== [] ? implode(', ', $parts) : $this->formatLocation($event);
    }

    private function loadedPrimaryLocationFor(EventOccurrence|EventSession $scope): ?EventLocation
    {
        if (! $scope->relationLoaded('locations')) {
            return null;
        }

        return $scope->locations->firstWhere('location_role', 'primary')
            ?? $scope->locations->first();
    }

    /**
     * Format dates for Google Calendar (YYYYMMDDTHHMMSS/YYYYMMDDTHHMMSS). An
     * explicit null end stays empty rather than inventing a duration.
     */
    protected function formatGoogleDates(?CarbonImmutable $startAt, ?CarbonImmutable $endAt): string
    {
        $start = $startAt?->setTimezone('UTC')->format('Ymd\THis\Z');
        $end = $endAt?->setTimezone('UTC')->format('Ymd\THis\Z');

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

        // Add persons
        if ($event->persons->isNotEmpty()) {
            $personNames = $event->persons->pluck('name')->join(', ');
            $parts[] = '';
            $parts[] = "Penceramah: {$personNames}";
        }

        // Add link to event page
        $parts[] = '';
        $parts[] = 'Maklumat lanjut: '.route('events.show', $event);

        return implode("\n", $parts);
    }

    /**
     * Normalize any schedule boundary (mutable/immutable Carbon, date string or
     * null) to an immutable instant. Anything unparseable resolves to null.
     */
    protected function normalizeDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof CarbonInterface || $value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    protected function nextCalendarWindow(Event $event): array
    {
        $windows = $this->eventWindows($event);
        $timezone = $event->timezone ?: config('app.timezone', 'UTC');
        $now = now($timezone);

        $nextWindow = collect($windows)
            ->first(fn (array $window): bool => $window['start']->setTimezone($timezone)->greaterThanOrEqualTo($now));

        if (! is_array($nextWindow)) {
            $nextWindow = $windows[count($windows) - 1] ?? null;
        }

        if (is_array($nextWindow)) {
            return [
                $nextWindow['start'],
                $nextWindow['end'],
            ];
        }

        // No fallback to the event window: with every occurrence hidden the
        // event dates would leak the hidden schedule (they mirror the
        // unfiltered primary occurrence).
        return [null, null];
    }

    /**
     * @return array<int, array{uid: string, start: CarbonImmutable, end: ?CarbonImmutable}>
     */
    protected function sessionWindowsForIcs(Event $event): array
    {
        return $this->eventWindows($event);
    }

    /**
     * Public schedule windows only: private/draft dates and sessions never
     * enter calendar output, whether the graph came pre-loaded or lazy.
     *
     * @return array<int, array{uid: string, start: CarbonImmutable, end: ?CarbonImmutable}>
     */
    protected function eventWindows(Event $event): array
    {
        $windows = [];

        $occurrences = $event->relationLoaded('occurrences')
            ? $event->occurrences
            : $event->occurrences()->with('sessions')->orderBy('starts_at')->get();

        foreach ($occurrences as $occurrence) {
            if (! $this->isPublicScheduleRow($occurrence)) {
                continue;
            }

            $sessions = $occurrence->relationLoaded('sessions')
                ? $occurrence->sessions
                : $occurrence->sessions()->orderBy('starts_at')->get();

            $publicSessions = $sessions->filter(fn (EventSession $session): bool => $this->isPublicScheduleRow($session));

            if ($publicSessions->isEmpty()) {
                $startAt = $this->normalizeDate($occurrence->starts_at);

                if ($startAt instanceof CarbonImmutable) {
                    $windows[] = [
                        'uid' => (string) $occurrence->id,
                        'start' => $startAt,
                        'end' => $this->normalizeDate($occurrence->ends_at),
                    ];
                }

                continue;
            }

            foreach ($publicSessions as $session) {
                $startAt = $this->normalizeDate($session->starts_at);

                if (! $startAt instanceof CarbonImmutable) {
                    continue;
                }

                $windows[] = [
                    'uid' => (string) $session->id,
                    'start' => $startAt,
                    'end' => $this->normalizeDate($session->ends_at),
                ];
            }
        }

        return $windows;
    }

    private function isPublicScheduleRow(EventOccurrence|EventSession $row): bool
    {
        return in_array((string) $row->status, Event::PUBLIC_SCHEDULE_STATUSES, true)
            && in_array((string) $row->visibility, Event::PUBLIC_SCHEDULE_VISIBILITIES, true);
    }

    /**
     * Format the event location through the shared resolver: default venue,
     * otherwise primary package venue, otherwise institution place.
     */
    protected function formatLocation(Event $event): string
    {
        $line1 = $event->resolvedLocationAddress()?->line1;

        $parts = array_values(array_filter([
            $event->resolvedLocationName(),
            is_string($line1) && trim($line1) !== '' ? $line1 : null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

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

    private function googleUrl(string $title, ?CarbonImmutable $start, ?CarbonImmutable $end, string $description, string $location): string
    {
        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $this->formatGoogleDates($start, $end),
            'details' => $description,
            'location' => $location,
        ]);
    }

    private function outlookUrl(string $title, ?CarbonImmutable $start, ?CarbonImmutable $end, string $description, string $location): string
    {
        return 'https://outlook.live.com/calendar/0/deeplink/compose?'.http_build_query([
            'path' => '/calendar/0/deeplink/compose',
            'rru' => 'addevent',
            'subject' => $title,
            'body' => $description,
            'location' => $location,
            'startdt' => $start?->toIso8601String(),
            'enddt' => $end?->toIso8601String(),
        ]);
    }

    private function office365Url(string $title, ?CarbonImmutable $start, ?CarbonImmutable $end, string $description, string $location): string
    {
        return 'https://outlook.office.com/calendar/0/deeplink/compose?'.http_build_query([
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $title,
            'body' => $description,
            'location' => $location,
            'startdt' => $start?->toIso8601String(),
            'enddt' => $end?->toIso8601String(),
        ]);
    }

    private function yahooUrl(string $title, ?CarbonImmutable $start, ?CarbonImmutable $end, string $description, string $location): string
    {
        return 'https://calendar.yahoo.com/?'.http_build_query([
            'v' => '60',
            'title' => $title,
            'st' => $start?->format('Ymd\THis'),
            'et' => $end?->format('Ymd\THis'),
            'desc' => $description,
            'in_loc' => $location,
        ]);
    }
}
