<?php

namespace App\Http\Controllers\Public;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Enums\EventVisibility;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\CalendarService;
use App\Services\PublicScheduleDiscoveryService;
use App\Support\Events\PublicSchedulePolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class EventsController extends Controller
{
    public function __construct(
        protected CalendarService $calendarService,
    ) {}

    /**
     * Download ICS calendar file for an event.
     */
    public function calendar(Event $event): Response
    {
        if (! $event->hasOccurrences()
            || $event->published_at === null
            || (! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true))
            || $event->visibility !== EventVisibility::Public
            || ($event->primaryOccurrence && in_array((string) $event->primaryOccurrence->status, ['postponed', 'rescheduled'], true))) {
            abort(404);
        }

        $icsContent = $this->calendarService->generateIcs($event);
        $filename = Str::slug($event->title).'.ics';

        return response($icsContent)
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Download a single-date ICS file with the date's own schedule and host.
     */
    public function occurrenceCalendar(Event $event, string $occurrenceSlug): Response
    {
        $this->assertEventCalendarReachable($event);

        $occurrence = app(PublicScheduleDiscoveryService::class)->findOccurrence($event, $occurrenceSlug);

        if (! $occurrence instanceof EventOccurrence
            || ! PublicSchedulePolicy::isPublicOccurrence($occurrence)
            || in_array((string) $occurrence->status, ['postponed', 'rescheduled', 'cancelled'], true)) {
            abort(404);
        }

        $occurrence->loadMissing(['locations' => self::publicLocationConstraint()]);

        $icsContent = $this->calendarService->generateIcsFor($event, $occurrence);
        $filename = Str::slug($occurrence->title ?: $event->title).'.ics';

        return response($icsContent)
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Download a single-session ICS file with the session's own schedule.
     */
    public function sessionCalendar(Event $event, string $occurrenceSlug, string $sessionSlug): Response
    {
        $this->assertEventCalendarReachable($event);

        $discovery = app(PublicScheduleDiscoveryService::class);
        $occurrence = $discovery->findOccurrence($event, $occurrenceSlug);

        if (! $occurrence instanceof EventOccurrence
            || ! PublicSchedulePolicy::isPublicOccurrence($occurrence)
            || in_array((string) $occurrence->status, ['postponed', 'rescheduled', 'cancelled'], true)) {
            abort(404);
        }

        $session = $discovery->findSession($occurrence, $sessionSlug);

        if (! $session instanceof EventSession
            || ! PublicSchedulePolicy::isMeaningfulSession($session)
            || in_array((string) $session->status, ['postponed', 'rescheduled', 'cancelled'], true)) {
            abort(404);
        }

        $occurrence->loadMissing(['locations' => self::publicLocationConstraint()]);
        $session->loadMissing(['locations' => self::publicLocationConstraint()]);

        $icsContent = $this->calendarService->generateIcsFor($event, $session, $occurrence);
        $filename = Str::slug($session->title).'.ics';

        return response($icsContent)
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    private static function publicLocationConstraint(): \Closure
    {
        return static function (Relation $query): void {
            if ($query->getParent() instanceof EventOccurrence) {
                $query->whereNull('event_session_id');
            }

            $query->where('status', 'active')->where('visibility', 'public');
        };
    }

    private function assertEventCalendarReachable(Event $event): void
    {
        if (! $event->hasOccurrences()
            || $event->published_at === null
            || (! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true))
            || $event->visibility !== EventVisibility::Public) {
            abort(404);
        }
    }
}
