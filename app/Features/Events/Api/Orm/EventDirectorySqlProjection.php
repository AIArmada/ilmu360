<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

/**
 * Immutable correlated event columns for the institution directory listing.
 *
 * @internal Owner-registered SQL projection; consumers apply each column with
 *           selectRaw() and never receive a mutable query builder.
 *
 * Consumers: Institutions InstitutionDirectoryQuery.
 */
final readonly class EventDirectorySqlProjection
{
    public function __construct(
        public EventDirectorySqlColumn $upcomingCount,
        public EventDirectorySqlColumn $nextEventId,
    ) {}
}
