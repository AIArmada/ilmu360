<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Immutable correlated event columns for the institution directory listing.
 *
 * Contains no mutable Eloquent builder; the owner supplies SQL and bindings.
 */
final readonly class EventDirectorySqlProjection
{
    public function __construct(
        public EventDirectorySqlColumn $upcomingCount,
        public EventDirectorySqlColumn $nextEventId,
    ) {}
}
