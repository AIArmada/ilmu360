<?php

namespace App\Features\Engagement\EntryPoints\Http;

use AIArmada\Engagement\Contracts\EngagementCounterService;
use App\Features\Engagement\Api\Data\EventSaveStateData;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventSaveCommands;
use App\Features\Engagement\EntryPoints\Http\Data\EventEngagementListItemData;
use App\Features\Events\Api\Enums\EventVisibility;
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

#[Group('Event Save', 'Authenticated saved-event endpoints for listing and idempotent event save state management.')]
class EventSaveController extends Controller
{
    public function __construct(
        private readonly EventScheduleQuery $events,
    ) {}

    /**
     * List all saved events for the authenticated user.
     */
    #[Endpoint(
        title: 'List saved events',
        description: 'Returns the authenticated user\'s saved public events from the `/me/events/saved` collection.',
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $savedEventIds = app(EventEngagement::class)->savedEventIds((string) $user->getKey());
        $page = $this->events->activeEventsForIds(
            $savedEventIds,
            max(1, $request->integer('page', 1)),
            ApiPagination::normalizePerPage($request->integer('per_page', 20), default: 20, max: 100),
        );

        return response()->json([
            'data' => collect($page['items'])
                ->map(fn (array $event): array => EventEngagementListItemData::fromPayload($event)->payload())
                ->all(),
            'meta' => [
                'request_id' => request()->header('X-Request-ID', (string) Str::uuid()),
                'pagination' => [
                    'page' => $page['page'],
                    'per_page' => $page['per_page'],
                    'has_more' => $page['has_more'],
                    'next_page' => $page['next_page'],
                ],
            ],
        ]);
    }

    /**
     * Save an event (bookmark) idempotently.
     */
    #[Endpoint(
        title: 'Save an event',
        description: 'Idempotently marks the target public event as saved for the authenticated user.',
    )]
    public function store(Request $request, Event $event): JsonResponse
    {
        if (! $event->hasOccurrences() || $event->published_at === null || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true) || $event->visibility !== EventVisibility::Public) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'This event cannot be saved.',
                ],
            ], 403);
        }

        $user = $this->currentUser($request);

        $result = app(EventSaveCommands::class)->save((string) $event->getKey(), (string) $user->getKey());

        if ($result['bookmark_id'] === null) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Event not found.',
                ],
            ], 404);
        }

        $created = $result['created'];
        $engagementCounter = app(EngagementCounterService::class);
        $engagementCounter->recalculateBookmarks($event);
        $savesCount = $engagementCounter->value($event, 'bookmarks');

        return response()->json([
            'message' => $created ? 'Event saved successfully.' : 'Event already saved.',
            'data' => new EventSaveStateData(true, $savesCount)->toArray(),
            'meta' => [
                'request_id' => request()->header('X-Request-ID', (string) Str::uuid()),
            ],
        ], $created ? 201 : 200);
    }

    /**
     * Remove a saved event (unbookmark) idempotently.
     */
    #[Endpoint(
        title: 'Remove a saved event',
        description: 'Idempotently removes the authenticated user\'s saved state for the target event.',
    )]
    public function destroy(Request $request, Event $event): JsonResponse
    {
        $user = $this->currentUser($request);

        $removed = app(EventSaveCommands::class)->unsave((string) $event->getKey(), (string) $user->getKey());

        $engagementCounter = app(EngagementCounterService::class);
        $engagementCounter->recalculateBookmarks($event);
        $savesCount = $engagementCounter->value($event, 'bookmarks');

        return response()->json([
            'message' => $removed ? 'Event save removed successfully.' : 'Event was not saved.',
            'data' => new EventSaveStateData(false, $savesCount)->toArray(),
            'meta' => [
                'request_id' => request()->header('X-Request-ID', (string) Str::uuid()),
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
