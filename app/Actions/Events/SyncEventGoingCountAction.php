<?php

namespace App\Actions\Events;

use App\Models\Event;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SyncEventGoingCountAction
{
    use AsAction;

    public function handle(Event $event): int
    {
        $goingCount = $event->goingBy()->active()->count();

        $event->forceFill(['going_count' => $goingCount])->saveQuietly();

        return $goingCount;
    }
}
