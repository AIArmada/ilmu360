<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Event link/audience projection synchronisation used by the root model hook.
 */
interface EventProjections
{
    /**
     * @param  array<string, string|null>  $writes
     */
    public function syncLinks(string $eventId, array $writes): void;

    /**
     * @param  array<string, mixed>  $audienceWrites
     * @param  array<string, mixed>  $profileWrites
     */
    public function syncAudiences(string $eventId, array $audienceWrites, array $profileWrites): void;
}
