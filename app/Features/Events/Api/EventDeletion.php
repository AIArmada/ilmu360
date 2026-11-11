<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * App-owned event deletion cleanup boundary.
 *
 * The root Event model's `deleting` hook delegates here so the cleanup of
 * membership, engagement, registration, submission, moderation, announcement
 * and media rows stays owned by Events. Package cascade cleanup still runs in
 * the model's `deleted` hook.
 */
interface EventDeletion
{
    /**
     * Remove the app-owned rows that point at the event before it is deleted.
     */
    public function cleanup(string $eventId): void;
}
