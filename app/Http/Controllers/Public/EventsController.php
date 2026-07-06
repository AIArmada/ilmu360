<?php

namespace App\Http\Controllers\Public;

use AIArmada\Events\Contracts\RegistrationServiceInterface;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventVisibility;
use App\Enums\ScheduleState;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\Notifications\EventNotificationService;
use App\Services\ShareTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class EventsController extends Controller
{
    public function __construct(
        protected CalendarService $calendarService,
        protected ShareTrackingService $shareTrackingService,
        protected EventNotificationService $eventNotificationService,
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
        RegistrationServiceInterface $registrations,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        /** @var User|null $user */
        $user = $request->user();

        $eventRegistration = $registrations->register([
            'event_id' => $event->id,
            'registrant_type' => $user?->getMorphClass(),
            'registrant_id' => (string) $user?->getKey(),
            'registration_type' => 'individual',
            'status' => 'confirmed',
            'source' => 'free_rsvp',
            'total_participants' => 1,
            'total_amount' => null,
            'currency' => null,
            'payment_status' => null,
            'participants' => [[
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'is_primary' => true,
                'is_purchaser' => true,
            ]],
        ]);

        $registration = Registration::findOrFail($eventRegistration->id);

        $this->shareTrackingService->recordOutcome(
            type: DawahShareOutcomeType::EventRegistration,
            outcomeKey: 'event_registration:registration:'.$registration->id,
            subject: $event,
            actor: $user,
            request: $request,
            metadata: [
                'registration_id' => $registration->id,
                'guest' => ! $user instanceof User,
            ],
        );

        if ($user instanceof User) {
            $this->eventNotificationService->notifyRegistrationConfirmed($registration);
        }

        return back()->with('success', 'You have been registered for this event!');
    }
}
