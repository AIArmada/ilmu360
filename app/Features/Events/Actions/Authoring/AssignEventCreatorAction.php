<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;

/** Preserve the original creator attribution when Events are first persisted. */
final readonly class AssignEventCreatorAction
{
    public function handle(Event $event): void
    {
        $creator = auth()->user();

        if (! $creator instanceof Model) {
            return;
        }

        if ($event->getAttribute('created_by_type') === null) {
            $event->setAttribute('created_by_type', $creator->getMorphClass());
        }

        if ($event->getAttribute('created_by_id') === null) {
            $event->setAttribute('created_by_id', $creator->getKey());
        }
    }
}
