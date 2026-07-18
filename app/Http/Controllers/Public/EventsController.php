<?php

namespace App\Http\Controllers\Public;

use AIArmada\Events\Actions\RegisterForFreeAction;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterEventRequest;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\Notifications\EventNotificationService;
use App\Services\ShareTrackingService;
use Illuminate\Http\RedirectResponse;
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
            || ($event->primaryOccurrence && in_array((string) $event->primaryOccurrence->status, ['postponed', 'rescheduled'], true))) {
            abort(404);
        }

        $icsContent = $this->calendarService->generateIcs($event);
        $filename = Str::slug($event->title).'.ics';

        return response($icsContent)
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public function register(
        RegisterEventRequest $request,
        Event $event,
        RegisterForFreeAction $registerForFree,
    ): RedirectResponse {
        abort_unless($event->isRegistrationAvailable(), 404);

        $validated = $request->validated();

        /** @var User|null $user */
        $user = $request->user();

        $eventRegistration = $registerForFree->execute(
            target: $event,
            participants: [[
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'is_primary' => true,
                'is_purchaser' => true,
            ]],
            registrant: $user,
            options: ['with_pass' => true],
        )->firstOrFail();

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
