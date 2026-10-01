<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Current transactional intent for institution curated slugs.
 *
 * A graph write protects its institution ID in the current transaction
 * record; deferred same-name slug regenerations skip protected IDs at
 * commit instead of replacing opaque curated bytes. Intent is scoped to
 * live transaction records only: it releases automatically on commit or
 * rollback, so ordinary saves outside the graph transaction regenerate
 * normally. ID protection only — never stored, rewritten, or status data.
 */
interface InstitutionSlugIntent
{
    /**
     * Protect the institution ID for the remainder of the current transaction.
     */
    public function protect(string $institutionId): void;

    /**
     * Whether the institution ID is protected by any live transaction record.
     */
    public function isProtected(string $institutionId): bool;
}
