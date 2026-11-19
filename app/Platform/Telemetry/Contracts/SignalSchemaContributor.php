<?php

declare(strict_types=1);

namespace App\Platform\Telemetry\Contracts;

/**
 * Publishes the allowlisted product-signal properties owned by one feature.
 *
 * Schema keys are event names. A key ending in `*` is a prefix family; the
 * registry resolves exact event names before prefix families.
 */
interface SignalSchemaContributor
{
    public function supports(string $event): bool;

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array;
}
