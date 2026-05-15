<?php

namespace App\Actions\Events;

use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveEventAction
{
    use AsAction;

    public function __construct(
        private readonly ShareTrackingService $shareTrackingService,
    ) {}

    /**
     * @return array{status: 'created'|'conflict'|'not_found', saves_count: int}
     */
    public function handle(Event $event, User $user, Request $request): array
    {
        $savedState = DB::transaction(function () use ($event, $user): array {
            $lockedEvent = Event::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedEvent instanceof Event) {
                return ['status' => 'not_found', 'saves_count' => 0];
            }

            $inserted = DB::table('event_saves')->insertOrIgnore([
                'user_id' => $user->getKey(),
                'event_id' => $lockedEvent->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return [
                    'status' => 'conflict',
                    'saves_count' => $this->syncSavesCount((string) $lockedEvent->getKey()),
                ];
            }

            return [
                'status' => 'created',
                'saves_count' => $this->syncSavesCount((string) $lockedEvent->getKey()),
            ];
        }, 3);

        if ($savedState['status'] === 'created') {
            $this->shareTrackingService->recordOutcome(
                type: DawahShareOutcomeType::EventSave,
                outcomeKey: 'event_save:user:'.$user->getKey().':event:'.$event->getKey(),
                subject: $event,
                actor: $user,
                request: $request,
                metadata: [
                    'event_id' => $event->getKey(),
                ],
            );
        }

        return $savedState;
    }

    private function syncSavesCount(string $eventId): int
    {
        $savesCount = (int) DB::table('event_saves')
            ->where('event_id', $eventId)
            ->count();

        Event::query()
            ->whereKey($eventId)
            ->update(['saves_count' => $savesCount]);

        return $savesCount;
    }
}
