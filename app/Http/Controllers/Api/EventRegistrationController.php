<?php

namespace App\Http\Controllers\Api;

use AIArmada\Events\Actions\RegisterForFreeAction;
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
    public function store(Request $request, Event $event, RegisterForFreeAction $registerForFree): JsonResponse
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

        return response()->json([
            'data' => EventRegistrationData::fromModel($registration)->toArray(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
            ],
        ], 201);
    }
}
