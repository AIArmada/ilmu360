<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Exposes an opaque fingerprint for event involvement changes.
 */
interface EventInvolvementFingerprint
{
    public function current(): string;
}
