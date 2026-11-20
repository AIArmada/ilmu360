<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Http;

use App\Features\Events\Api\EventScheduleSql;
use App\Features\Events\Queries\PrimaryOccurrenceSql;

/**
 * Boundary adapter over the primary-occurrence SQL expressions.
 */
final readonly class PrimaryOccurrenceScheduleSql implements EventScheduleSql
{
    public function endInstantSql(?string $eventsTable = null): string
    {
        return PrimaryOccurrenceSql::endInstant($eventsTable);
    }
}
