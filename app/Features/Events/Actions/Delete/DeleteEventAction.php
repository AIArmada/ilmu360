<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Delete;

use App\Features\Events\Support\EventModel;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Named event delete entry point.
 *
 * The root Event model hooks still run the same cleanup for direct deletes;
 * this action only gives app-owned callers a named path that resolves through
 * the event morph alias.
 */
final readonly class DeleteEventAction
{
    use AsAction;

    public function handle(string $eventId): bool
    {
        $event = EventModel::query('event')->withoutOwnerScope()->find($eventId);

        if (! $event instanceof Model) {
            return false;
        }

        return (bool) $event->delete();
    }
}
