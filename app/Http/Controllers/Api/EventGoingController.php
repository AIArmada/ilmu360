<?php

namespace App\Http\Controllers\Api;

use App\Actions\Events\MarkEventGoingAction;
use App\Actions\Events\RemoveEventGoingAction;
use App\Data\Api\EventEngagement\EventEngagementListItemData;
use App\Data\Api\EventGoing\EventGoingStateData;
use App\Enums\EventVisibility;
use App\Enums\ScheduleState;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Support\Api\ApiPagination;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Event Going', 'Authenticated event-going endpoints for listing and idempotent event going state management.')]
class EventGoingController extends Controller
{
    #[Endpoint(
        title: 'List going events',
        description: 'Returns the authenticated user\'s current going-event list from the `/me/events/going` collection.',
    )]
    public function index(Request $request): JsonResponse
    {
        $goingEvents = $this->currentUser($request)
            ->goingEvents()
            ->with(['institution:id,name,slug', 'venue:id,name', 'speakers:id,name,slug'])
            ->active()
            ->orderBy('starts_at')
            ->simplePaginate(ApiPagination::normalizePerPage($request->integer('per_page', 20), default: 20, max: 100));

        return response()->json([
            'data' => collect($goingEvents->items())
                ->map(fn (Event $event): array => EventEngagementListItemData::fromModel($event)->payload())
                ->all(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
                'pagination' => [
                    ...ApiPagination::simplePaginationMeta($goingEvents),
                ],
            ],
        ]);
    }

    #[Endpoint(
        title: 'Mark an event as going',
        description: 'Idempotently marks the target public event as going for the authenticated user when it is still engageable.',
    )]
    public function store(Request $request, Event $event, MarkEventGoingAction $markEventGoingAction): JsonResponse
    {
        if (! $event->is_active
            || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)
            || $event->visibility !== EventVisibility::Public
            || $event->schedule_state === ScheduleState::Postponed) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'This event cannot be marked as going.',
                ],
            ], 403);
        }

        if ($event->starts_at !== null && $event->starts_at->isPast()) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Cannot mark going for past events.',
                ],
            ], 403);
        }

        $goingState = $markEventGoingAction->handle($event, $this->currentUser($request), $request);

        if ($goingState['status'] === 'not_found') {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Event not found.',
                ],
            ], 404);
        }

        $created = $goingState['status'] === 'created';

        return response()->json([
            'message' => $created ? 'Going recorded successfully.' : 'Event already marked as going.',
            'data' => EventGoingStateData::fromState(true, $goingState['going_count'])->toArray(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
            ],
        ], $created ? 201 : 200);
    }

    #[Endpoint(
        title: 'Remove going state',
        description: 'Idempotently removes the authenticated user\'s going state for the target event.',
    )]
    public function destroy(Request $request, Event $event, RemoveEventGoingAction $removeEventGoingAction): JsonResponse
    {
        $result = $removeEventGoingAction->handle($event->id, $this->currentUser($request));

        return response()->json([
            'message' => $result['deleted'] ? 'Going removed successfully.' : 'Event was not marked as going.',
            'data' => EventGoingStateData::fromState(false, $result['going_count'])->toArray(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
            ],
        ]);
    }

    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
