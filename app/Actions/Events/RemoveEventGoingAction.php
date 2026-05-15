<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class RemoveEventGoingAction
{
    use AsAction;

    /**
     * @return array{deleted: bool, going_count: int}
     */
    public function handle(string $eventId, User $user): array
    {
        return DB::transaction(function () use ($eventId, $user): array {
            $event = Event::query()
                ->whereKey($eventId)
                ->lockForUpdate()
                ->first();

            $deletedRows = DB::table('event_attendees')
                ->where('user_id', $user->getKey())
                ->where('event_id', $eventId)
                ->delete();

            if ($deletedRows === 0) {
                return [
                    'deleted' => false,
                    'going_count' => $event instanceof Event ? $this->syncGoingCount($eventId) : 0,
                ];
            }

            return [
                'deleted' => true,
                'going_count' => $event instanceof Event ? $this->syncGoingCount($eventId) : 0,
            ];
        }, 3);
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
