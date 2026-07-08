<?php

namespace App\Http\Controllers\Api;

use AIArmada\Events\Contracts\RegistrationServiceInterface;
use AIArmada\Events\Events\EventFreeRegistrationConfirmed;
use App\Data\Api\EventRegistration\EventRegistrationData;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Event Registration', 'Public event registration submission endpoints. Authenticated registration state is exposed via `GET /events/{event}/me`.')]
class EventRegistrationController extends Controller
{
    #[Endpoint(
        title: 'Register for an event',
        description: 'Creates a registration for the target event using guest contact details or the current authenticated user context.',
    )]
    public function store(Request $request, Event $event, RegistrationServiceInterface $registrations): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();

        if (! $user instanceof User && blank($validated['email'] ?? null) && blank($validated['phone'] ?? null)) {
            return response()->json([
                'message' => 'Please provide either email or phone number.',
                'errors' => ['contact' => ['Please provide either email or phone number.']],
            ], 422);
        }

        $eventRegistration = $registrations->register([
            'event_id' => $event->id,
            'registrant_type' => $user instanceof User ? $user->getMorphClass() : null,
            'registrant_id' => $user instanceof User ? (string) $user->getKey() : null,
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

        EventFreeRegistrationConfirmed::dispatch($registration, true);

        return response()->json([
            'data' => EventRegistrationData::fromModel($registration)->toArray(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
            ],
        ], 201);
    }
}
