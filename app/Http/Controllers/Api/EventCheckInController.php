<?php

namespace App\Http\Controllers\Api;

use App\Actions\Events\RecordEventCheckInAction;
use App\Actions\Events\ResolveEventCheckInStateAction;
use App\Data\Api\EventCheckIn\EventCheckInData;
use App\Http\Controllers\Controller;
use App\Models\Event;
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
        ResolveEventCheckInStateAction $resolveEventCheckInStateAction,
        RecordEventCheckInAction $recordEventCheckInAction,
    ): JsonResponse {
        $user = $this->currentUser($request);
        $state = $resolveEventCheckInStateAction->handle($event->loadMissing('settings'), $user);

        if (! $state['available']) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => (string) ($state['reason'] ?? 'Check-in is not available.'),
                ],
            ], 403);
        }

        $result = $recordEventCheckInAction->handle(
            $event,
            $user,
            $state['registration_id'],
            $state['method'],
            $request,
        );

        $statusCode = $result['status'] === 'created' ? 201 : 200;
        $message = $result['status'] === 'created'
            ? 'Check-in recorded successfully.'
            : 'You have already checked in for this event.';

        return response()->json([
            'message' => $message,
            'data' => [
                'status' => $result['status'],
                'checkin' => EventCheckInData::fromModel($result['checkin'])->toArray(),
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
