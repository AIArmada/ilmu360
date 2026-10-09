<?php

namespace App\Http\Controllers\Api;

use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Bookmark;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Data\Api\EventEngagement\EventEngagementListItemData;
use App\Data\Api\EventSave\EventSaveStateData;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventVisibility;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Api\ApiPagination;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with(['institution:id,name,slug', 'venue:id,name', 'persons:id,name,slug'])
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

        $result = DB::transaction(function () use ($user, $event, $request): array {
            $bookmark = app(EngagementManager::class)->bookmark($user, $event);

            if (! $bookmark) {
                return ['bookmark' => null, 'created' => false];
            }

            $created = $bookmark->wasRecentlyCreated;

            if ($created) {
                app(ShareTrackingService::class)->recordOutcome(
                    type: DawahShareOutcomeType::EventSave,
                    businessKey: ShareOutcomeBusinessKey::eventSave((string) $bookmark->getKey()),
                    subject: $event,
                    actor: $user,
                    request: $request,
                    metadata: ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()],
                    occurredAt: $bookmark->created_at,
                );
            }

            return ['bookmark' => $bookmark, 'created' => $created];
        });

        if (! $result['bookmark']) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Event not found.',
                ],
            ], 404);
        }

        $created = $result['created'];
        app(EngagementCounterService::class)->recalculateBookmarks($event);
        $savesCount = Bookmark::forBookmarkable($event)->active()->count();

        return response()->json([
            'message' => $created ? 'Event saved successfully.' : 'Event already saved.',
            'data' => EventSaveStateData::fromState(true, $savesCount)->toArray(),
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

        $wasSaved = DB::transaction(function () use ($user, $event, $request): bool {
            $bookmark = Bookmark::forBookmarker($user)->forBookmarkable($event)->active()->lockForUpdate()->first();
            $wasSaved = $bookmark instanceof Bookmark;
            app(EngagementManager::class)->removeBookmark($user, $event);

            if ($wasSaved && $bookmark instanceof Bookmark) {
                app(ReverseShareOutcomeAction::class)->handle(
                    ShareOutcomeBusinessKey::eventSave((string) $bookmark->getKey()),
                    'save_removed',
                    $user,
                    $request,
                );
            }

            return $wasSaved;
        });
        app(EngagementCounterService::class)->recalculateBookmarks($event);
        $savesCount = Bookmark::forBookmarkable($event)->active()->count();

        return response()->json([
            'message' => $wasSaved ? 'Event save removed successfully.' : 'Event was not saved.',
            'data' => EventSaveStateData::fromState(false, $savesCount)->toArray(),
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
