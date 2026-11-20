<?php

namespace App\Features\Registration\EntryPoints\Http;

use App\Features\Events\Api\EventTicketing;
use App\Features\Events\Api\Orm\EventRegistrationNotifications;
use App\Features\Events\Models\Event;
use App\Features\Registration\Api\AdmissionRegistration;
use App\Features\Registration\Api\Data\EventRegistrationData;
use App\Features\Registration\EntryPoints\Http\Requests\RegisterEventRequest;
use App\Features\Registration\Models\Registration;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

#[Group('Event Registration', 'Public event registration submission endpoints. Authenticated registration state is exposed via `GET /events/{event}/me`.')]
class EventRegistrationController extends Controller
{
    public function __construct(
        private readonly EventRegistrationNotifications $eventNotificationService,
    ) {}

    #[Endpoint(
        title: 'Register for an event',
        description: 'Creates a registration for the target event using guest contact details or the current authenticated user context.',
    )]
    public function store(
        RegisterEventRequest $request,
        Event $event,
        AdmissionRegistration $admissions,
        EventTicketing $ticketingPolicy,
    ): JsonResponse {
        abort_unless($event->isRegistrationAvailable(), 404);
        abort_if($ticketingPolicy->eventRequiresTicketSelection((string) $event->getKey()), 404);

        $validated = $request->validated();

        $user = $request->user();

        $result = $admissions->registerFree(
            eventId: (string) $event->getKey(),
            participants: [[
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'is_primary' => true,
                'is_purchaser' => true,
            ]],
            registrantId: $user instanceof User ? (string) $user->getKey() : null,
            options: ['with_pass' => true],
            request: $request,
        );

        $registration = Registration::query()->findOrFail(
            (string) ($result['primary_registration_id'] ?? ''),
        );

        if ($user instanceof User) {
            $this->eventNotificationService->registrationConfirmed($registration);
        }

        return response()->json([
            'data' => new EventRegistrationData(
                id: (string) $registration->id,
                event_id: (string) $registration->event_id,
                user_id: $registration->registrant instanceof User ? (string) $registration->registrant->getKey() : null,
                name: $registration->resolvedName() ?? '',
                email: $registration->resolvedEmail(),
                phone: $registration->resolvedPhone(),
                status: $registration->statusValue(),
                created_at: $registration->created_at?->format(DATE_ATOM),
            )->toArray(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
            ],
        ], 201);
    }
}
