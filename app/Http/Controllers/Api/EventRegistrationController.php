<?php

namespace App\Http\Controllers\Api;

use App\Data\Api\EventRegistration\EventRegistrationData;
use App\Features\Registration\Api\AdmissionRegistration;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterEventRequest;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\Support\Events\EventTicketingPolicy;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

#[Group('Event Registration', 'Public event registration submission endpoints. Authenticated registration state is exposed via `GET /events/{event}/me`.')]
class EventRegistrationController extends Controller
{
    public function __construct(
        private readonly EventNotificationService $eventNotificationService,
    ) {}

    #[Endpoint(
        title: 'Register for an event',
        description: 'Creates a registration for the target event using guest contact details or the current authenticated user context.',
    )]
    public function store(
        RegisterEventRequest $request,
        Event $event,
        AdmissionRegistration $admissions,
        EventTicketingPolicy $ticketingPolicy,
    ): JsonResponse {
        abort_unless($event->isRegistrationAvailable(), 404);
        abort_if($ticketingPolicy->requiresTicketSelection($event), 404);

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
            $this->eventNotificationService->notifyRegistrationConfirmed($registration);
        }

        return response()->json([
            'data' => EventRegistrationData::fromModel($registration)->toArray(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
            ],
        ], 201);
    }
}
