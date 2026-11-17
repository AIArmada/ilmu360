<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

/** Direct contribution editing through the subject's authoring feature. */
interface ContributionDirectUpdates
{
    /** @param array<string, mixed> $changes */
    public function apply(string $entityType, string $entityId, array $changes): void;
}
