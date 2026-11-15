<?php

declare(strict_types=1);

namespace App\Shared\Contributions;

/**
 * Pending contribution request guard.
 *
 * The conventional action keeps the user-facing validation message and lookup;
 * Contributions consumes the check while it owns the request lifecycle.
 */
interface ContributionRequestGuard
{
    public function ensureNoPending(string $entityType, string $entityId): void;
}
