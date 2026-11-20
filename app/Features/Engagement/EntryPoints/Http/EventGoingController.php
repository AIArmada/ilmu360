<?php

namespace App\Features\Engagement\EntryPoints\Http;

use AIArmada\Engagement\Models\Response;
use App\Features\Engagement\Api\Data\EventGoingStateData;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventGoingCommands;
use App\Features\Engagement\EntryPoints\Http\Data\EventEngagementListItemData;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventScheduleQuery;
use App\Features\Events\Models\Event;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Api\ApiPagination;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Event Going', 'Authenticated event-going endpoints for listing and idempotent event going state management.')]
class EventGoingController extends Controller
{
    public function __construct(
        private readonly EventScheduleQuery $events,
        private readonly EventAuthoring $eventAuthoring,
    ) {}

    #[Endpoint(
        title: 'List going events',
        description: 'Returns the authenticated user\'s current going-event list from the `/me/events/going` collection.',
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $goingEventIds = app(EventEngagement::class)->goingEventIds((string) $user->getKey());
        $page = $this->events->activeEventsForIds(
            $goingEventIds,
            max(1, $request->integer('page', 1)),
            ApiPagination::normalizePerPage($request->integer('per_page', 20), default: 20, max: 100),
        );

        $goingResponses = Response::query()
            ->where('responder_type', $user->getMorphClass())
            ->where('responder_id', $user->getKey())
            ->where('respondable_type', $this->eventAuthoring->eventMorphType())
            ->whereIn('respondable_id', array_column($page['items'], 'id'))
            ->where('response_type', 'going')
            ->where('status', 'active')
            ->get()
            ->keyBy(static fn (Response $response): string => (string) $response->respondable_id);

        return response()->json([
            'data' => collect($page['items'])
                ->map(fn (array $event): array => EventEngagementListItemData::fromPayload(
                    $event,
                    $goingResponses->get((string) $event['id']),
                )->payload())
                ->all(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
                'pagination' => [
                    'page' => $page['page'],
                    'per_page' => $page['per_page'],
                    'has_more' => $page['has_more'],
                    'next_page' => $page['next_page'],
                ],
            ],
        ]);
    }

    #[Endpoint(
        title: 'Mark an event as going',
        description: 'Idempotently marks the target public event as going for the authenticated user when it is still engageable.',
    )]
    public function store(Request $request, Event $event, EventGoingCommands $eventGoingCommands): JsonResponse
    {
        $isPostponed = $event->primaryOccurrence && in_array((string) $event->primaryOccurrence->status, ['postponed', 'rescheduled'], true);

        if (! $event->hasOccurrences()
            || $event->published_at === null
            || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)
            || $event->visibility !== EventVisibility::Public
            || $isPostponed) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'This event cannot be marked as going.',
                ],
            ], 403);
        }

        if ($event->goingWindow()->hasEndedAt(now())) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Cannot mark going for past events.',
                ],
            ], 403);
        }

        $goingState = $eventGoingCommands->mark((string) $event->getKey(), (string) $this->currentUser($request)->getKey());
        $created = $goingState['status'] === 'created';

        $stateData = new EventGoingStateData(true, $goingState['going_count']);

        return response()->json([
            'message' => $created ? 'Going recorded successfully.' : 'Event already marked as going.',
            'data' => (object) $stateData->toArray(),
            'meta' => [
                'request_id' => $request->header('X-Request-ID', (string) Str::uuid()),
            ],
        ], $created ? 201 : 200);
    }

    #[Endpoint(
        title: 'Remove going state',
        description: 'Idempotently removes the authenticated user\'s going state for the target event.',
    )]
    public function destroy(Request $request, Event $event, EventGoingCommands $eventGoingCommands): JsonResponse
    {
        $result = $eventGoingCommands->remove((string) $event->getKey(), (string) $this->currentUser($request)->getKey());

        $stateData = new EventGoingStateData(false, $result['going_count']);

        return response()->json([
            'message' => $result['deleted'] ? 'Going removed successfully.' : 'Event was not marked as going.',
            'data' => (object) $stateData->toArray(),
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
