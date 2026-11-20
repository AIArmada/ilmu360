<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Primary-occurrence SQL boundary for cross-feature time scopes.
 */
interface EventScheduleSql
{
    /**
     * Effective end instant of the event schedule, falling back to its start
     * when an occurrence has no end time.
     */
    public function endInstantSql(?string $eventsTable = null): string;
}
