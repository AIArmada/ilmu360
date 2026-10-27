<?php

namespace App\Http\Controllers\Api;

use AIArmada\Engagement\Models\Response;
use App\Actions\Events\Engagement\MarkEventGoingAction;
use App\Actions\Events\Engagement\RemoveEventGoingAction;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Data\Api\EventEngagement\EventEngagementListItemData;
use App\Data\Api\EventGoing\EventGoingStateData;
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

#[Group('Event Going', 'Authenticated event-going endpoints for listing and idempotent event going state management.')]
class EventGoingController extends Controller
{
    #[Endpoint(
        title: 'List going events',
        description: 'Returns the authenticated user\'s current going-event list from the `/me/events/going` collection.',
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $goingEvents = Event::query()
            ->whereHas('goingBy', fn ($query) => $query
                ->where('responder_type', $user->getMorphClass())
                ->where('responder_id', $user->getKey())
                ->where('status', 'active'))
            ->with([
                'institution:id,name,slug',
                'venue:id,name',
                'persons:id,name,slug',
                'goingBy' => fn ($query) => $query
                    ->where('responder_type', $user->getMorphClass())
                    ->where('responder_id', $user->getKey())
                    ->where('status', 'active'),
            ])
            ->active()
            ->orderBy('starts_at')
            ->simplePaginate(ApiPagination::normalizePerPage($request->integer('per_page', 20), default: 20, max: 100));

        return response()->json([
            'data' => collect($goingEvents->items())
                ->map(fn (Event $event): array => EventEngagementListItemData::fromModel($event, $event->goingBy->first())->payload())
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

        if ($event->starts_at !== null && $event->starts_at->isPast()) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Cannot mark going for past events.',
                ],
            ], 403);
        }

        $result = DB::transaction(function () use ($event, $request, $markEventGoingAction): array {
            $goingState = $markEventGoingAction->handle($event, $this->currentUser($request), $request);
            $created = $goingState['status'] === 'created';

            // Only rows created by this call freeze an outcome decision.
            // Reactivations reuse the response row (status 'created' but
            // response_created false) and must never late-credit an old
            // transition through a new cookie.
            if ($goingState['response_created']) {
                $response = Response::query()
                    ->where('respondable_type', $event->getMorphClass())
                    ->where('respondable_id', $event->getKey())
                    ->where('responder_type', $this->currentUser($request)->getMorphClass())
                    ->where('responder_id', $this->currentUser($request)->getKey())
                    ->where('response_type', 'going')
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if ($response instanceof Response) {
                    app(ShareTrackingService::class)->recordOutcome(
                        type: DawahShareOutcomeType::EventGoing,
                        businessKey: ShareOutcomeBusinessKey::eventGoing((string) $response->getKey()),
                        subject: $event,
                        actor: $this->currentUser($request),
                        request: $request,
                        metadata: ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()],
                        occurredAt: $response->created_at,
                    );
                }
            }

            return ['status' => $goingState['status'], 'going_count' => $goingState['going_count'], 'created' => $created];
        });

        if ($result['status'] === 'not_found') {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Event not found.',
                ],
            ], 404);
        }

        $created = $result['created'];
        $goingState = ['status' => $result['status'], 'going_count' => $result['going_count']];

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
        $result = DB::transaction(function () use ($event, $request, $removeEventGoingAction): array {
            $going = Response::query()
                ->where('respondable_type', $event->getMorphClass())
                ->where('respondable_id', $event->getKey())
                ->where('responder_type', $this->currentUser($request)->getMorphClass())
                ->where('responder_id', $this->currentUser($request)->getKey())
                ->where('response_type', 'going')
                ->active()
                ->lockForUpdate()
                ->first();

            $removed = $removeEventGoingAction->handle($event->id, $this->currentUser($request));

            if ($removed['deleted'] && $going instanceof Response) {
                app(ReverseShareOutcomeAction::class)->handle(
                    ShareOutcomeBusinessKey::eventGoing((string) $going->getKey()),
                    'going_removed',
                    $this->currentUser($request),
                    $request,
                );
            }

            return $removed;
        });

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
