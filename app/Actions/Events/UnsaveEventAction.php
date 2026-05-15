<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class UnsaveEventAction
{
    use AsAction;

    /**
     * @return array{deleted: bool, saves_count: int}
     */
    public function handle(string $eventId, User $user): array
    {
        return DB::transaction(function () use ($eventId, $user): array {
            $event = Event::query()
                ->whereKey($eventId)
                ->lockForUpdate()
                ->first();

            $deletedRows = DB::table('event_saves')
                ->where('user_id', $user->getKey())
                ->where('event_id', $eventId)
                ->delete();

            if ($deletedRows === 0) {
                return [
                    'deleted' => false,
                    'saves_count' => $event instanceof Event ? $this->syncSavesCount($eventId) : 0,
                ];
            }

            return [
                'deleted' => true,
                'saves_count' => $event instanceof Event ? $this->syncSavesCount($eventId) : 0,
            ];
        }, 3);
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
