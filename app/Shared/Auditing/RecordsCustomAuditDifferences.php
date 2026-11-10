<?php

declare(strict_types=1);

namespace App\Shared\Auditing;

/**
 * Capability boundary for models that can persist explicit before/after audit
 * differences (used by Directory relation actions).
 */
interface RecordsCustomAuditDifferences
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordCustomAuditDifferences(string $event, array $before, array $after): void;
}
