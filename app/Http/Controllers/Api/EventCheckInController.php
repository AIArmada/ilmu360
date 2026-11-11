<?php

namespace App\Http\Controllers\Api;

use App\Data\Api\EventCheckIn\EventCheckInData;
use App\Features\Registration\Api\AttendanceCommands;
use App\Features\Registration\Api\AttendanceState;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Event Check-In', 'Authenticated event self-check-in mutation endpoint. Current check-in availability and state are exposed via `GET /events/{event}/me`.')]
class EventCheckInController extends Controller
{
    #[Endpoint(
        title: 'Record an event check-in',
        description: 'Records a self-check-in for the authenticated user when the current check-in state allows it.',
    )]
    public function store(
        Request $request,
        Event $event,
        AttendanceState $attendanceState,
        AttendanceCommands $attendanceCommands,
    ): JsonResponse {
        $user = $this->currentUser($request);
        $state = $attendanceState->forUser(
            (string) $event->getKey(),
            (string) $user->getKey(),
        );

        if (! $state['available']) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => (string) ($state['reason'] ?? 'Check-in is not available.'),
                ],
            ], 403);
        }

        $result = $attendanceCommands->recordSelfCheckIn(
            eventId: (string) $event->getKey(),
            userId: (string) $user->getKey(),
            registrationId: $state['registration_id'],
            method: $state['method'],
            request: $request,
        );

        $checkin = EventCheckin::query()->findOrFail($result['checkin_id']);

        $statusCode = $result['status'] === 'created' ? 201 : 200;
        $message = $result['status'] === 'created'
            ? 'Check-in recorded successfully.'
            : 'You have already checked in for this event.';

        return response()->json([
            'message' => $message,
            'data' => [
                'status' => $result['status'],
                'checkin' => EventCheckInData::fromModel($checkin)->toArray(),
            ],
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
            ],
        ], $statusCode);
    }

    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
