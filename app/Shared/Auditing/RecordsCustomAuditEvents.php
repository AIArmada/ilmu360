<?php

declare(strict_types=1);

namespace App\Shared\Auditing;

/**
 * Capability boundary for models that persist explicit custom audit events.
 */
interface RecordsCustomAuditEvents
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordCustomAudit(string $event, array $oldValues, array $newValues): void;
}
