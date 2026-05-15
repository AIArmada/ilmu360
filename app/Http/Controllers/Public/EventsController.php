<?php

namespace App\Http\Controllers\Public;

use App\Actions\Events\RegisterForEventAction;
use App\Enums\EventVisibility;
use App\Enums\ScheduleState;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\CalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        if ((! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true))
            || $event->visibility !== EventVisibility::Public
            || $event->schedule_state === ScheduleState::Postponed) {
            abort(404);
        }

        $icsContent = $this->calendarService->generateIcs($event);
        $filename = Str::slug($event->title).'.ics';

        return response($icsContent)
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public function register(
        Request $request,
        Event $event,
        RegisterForEventAction $registerForEventAction,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        /** @var User|null $user */
        $user = $request->user();

        $registerForEventAction->handle($event, $validated, $user, $request);

        return back()->with('success', 'You have been registered for this event!');
    }
}
