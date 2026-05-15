<?php

namespace App\Actions\Events;

use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkEventGoingAction
{
    use AsAction;

    public function __construct(
        private ShareTrackingService $shareTrackingService,
    ) {}

    /**
     * @return array{status: 'created'|'conflict'|'not_found', going_count: int}
     */
    public function handle(Event $event, User $user, Request $request): array
    {
        $goingState = DB::transaction(function () use ($event, $user): array {
            $lockedEvent = Event::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedEvent instanceof Event) {
                return ['status' => 'not_found', 'going_count' => 0];
            }

            $inserted = DB::table('event_attendees')->insertOrIgnore([
                'user_id' => $user->getKey(),
                'event_id' => $lockedEvent->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return [
                    'status' => 'conflict',
                    'going_count' => $this->syncGoingCount((string) $lockedEvent->getKey()),
                ];
            }

            return [
                'status' => 'created',
                'going_count' => $this->syncGoingCount((string) $lockedEvent->getKey()),
            ];
        }, 3);

        if ($goingState['status'] === 'created') {
            $this->shareTrackingService->recordOutcome(
                type: DawahShareOutcomeType::EventGoing,
                outcomeKey: 'event_going:user:'.$user->getKey().':event:'.$event->getKey(),
                subject: $event,
                actor: $user,
                request: $request,
                metadata: [
                    'event_id' => $event->getKey(),
                ],
            );
        }

        return $goingState;
    }

    private function syncGoingCount(string $eventId): int
    {
        $goingCount = (int) DB::table('event_attendees')
            ->where('event_id', $eventId)
            ->count();

        Event::query()
            ->whereKey($eventId)
            ->update(['going_count' => $goingCount]);

        return $goingCount;
    }
}
