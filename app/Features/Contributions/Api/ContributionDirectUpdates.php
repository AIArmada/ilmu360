<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

use Closure;

/** Direct contribution editing through the subject's authoring feature. */
interface ContributionDirectUpdates
{
    /**
     * @param  array<string, mixed>  $changes
     * @param  (Closure(): void)|null  $writeMedia
     */
    public function apply(
        string $actorId,
        string $entityType,
        string $entityId,
        array $changes,
        ?Closure $writeMedia = null,
    ): void;
}
