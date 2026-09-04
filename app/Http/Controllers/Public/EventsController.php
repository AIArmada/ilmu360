<?php

namespace App\Http\Controllers\Public;

use App\Enums\EventVisibility;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\CalendarService;
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

}
