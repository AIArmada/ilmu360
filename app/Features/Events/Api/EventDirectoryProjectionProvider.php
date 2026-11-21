<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * A pure value contract for institution event counts and next-event identifiers.
 * The Events owner constructs the correlated SQL without exposing mutable builders.
 */
interface EventDirectoryProjectionProvider
{
    public function institutionDirectoryEventColumns(): EventDirectorySqlProjection;

    /**
     * Card details for the already-selected next event ids, hydrated in one
     * batched query.
     *
     * @param  list<string>  $eventIds
     * @return array<string, array{slug: string, title: string, next_event_starts_at: string}>
     */
    public function institutionNextEventDetails(array $eventIds): array;
}
