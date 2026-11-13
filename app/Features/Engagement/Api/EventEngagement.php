<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

/**
 * Read-only save/going state for event cards and event engagement surfaces.
 *
 * Implementations may query the package engagement tables, but callers receive
 * only immutable scalar state.
 */
interface EventEngagement
{
    public function stateFor(string $accountId, string $eventId): EventEngagementState;

    /**
     * @param  list<string>  $eventIds
     * @return array<string, EventEngagementState>
     */
    public function statesFor(string $accountId, array $eventIds): array;

    /**
     * @return list<string>
     */
    public function savedEventIds(string $accountId): array;

    /**
     * @return list<string>
     */
    public function goingEventIds(string $accountId): array;
}
