<?php

namespace App\Http\Controllers\Api;

use App\Actions\Events\SaveEventAction;
use App\Actions\Events\UnsaveEventAction;
use App\Data\Api\EventEngagement\EventEngagementListItemData;
use App\Data\Api\EventSave\EventSaveStateData;
use App\Enums\EventVisibility;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Support\Api\ApiPagination;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Event Save', 'Authenticated saved-event endpoints for listing and idempotent event save state management.')]
class EventSaveController extends Controller
{
    /**
     * List all saved events for the authenticated user.
     */
    #[Endpoint(
        title: 'List saved events',
        description: 'Returns the authenticated user\'s saved public events from the `/me/events/saved` collection.',
    )]
    public function index(Request $request): JsonResponse
    {
        $savedEvents = $this->currentUser($request)
            ->savedEvents()
            ->with(['institution:id,name,slug', 'venue:id,name', 'speakers:id,name,slug'])
            ->active()
            ->orderBy('starts_at')
            ->simplePaginate(ApiPagination::normalizePerPage($request->integer('per_page', 20), default: 20, max: 100));

        return response()->json([
            'data' => collect($savedEvents->items())
                ->map(fn (Event $event): array => EventEngagementListItemData::fromModel($event)->payload())
                ->all(),
            'meta' => [
                'request_id' => request()->header('X-Request-ID', (string) Str::uuid()),
                'pagination' => [
                    ...ApiPagination::simplePaginationMeta($savedEvents),
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
    public function store(Request $request, Event $event, SaveEventAction $saveEventAction): JsonResponse
    {
        if (! $event->is_active || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true) || $event->visibility !== EventVisibility::Public) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'This event cannot be saved.',
                ],
            ], 403);
        }

        $user = $this->currentUser($request);
        $savedState = $saveEventAction->handle($event, $user, $request);

        if ($savedState['status'] === 'not_found') {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Event not found.',
                ],
            ], 404);
        }

        $created = $savedState['status'] === 'created';

        return response()->json([
            'message' => $created ? 'Event saved successfully.' : 'Event already saved.',
            'data' => EventSaveStateData::fromState(true, $savedState['saves_count'])->toArray(),
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
    public function destroy(Request $request, Event $event, UnsaveEventAction $unsaveEventAction): JsonResponse
    {
        $result = $unsaveEventAction->handle((string) $event->getKey(), $this->currentUser($request));

        return response()->json([
            'message' => $result['deleted'] ? 'Event save removed successfully.' : 'Event was not saved.',
            'data' => EventSaveStateData::fromState(false, $result['saves_count'])->toArray(),
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
