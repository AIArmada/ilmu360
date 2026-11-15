<?php

declare(strict_types=1);

namespace App\Features\Engagement\Actions;

use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Response;
use App\Features\Engagement\Api\EventEngagementDeletion;

/**
 * Deletes the active event bookmarks and all event-going responses.
 *
 * Package model queries preserve the configured tables and owner scopes.
 */
final readonly class DeleteEventEngagementAction implements EventEngagementDeletion
{
    public function deleteForEvent(string $eventId, string $eventMorphType): void
    {
        Bookmark::query()
            ->where('bookmarkable_type', $eventMorphType)
            ->where('bookmarkable_id', $eventId)
            ->active()
            ->delete();

        Response::query()
            ->where('respondable_type', $eventMorphType)
            ->where('respondable_id', $eventId)
            ->where('response_type', 'going')
            ->delete();
    }
}
