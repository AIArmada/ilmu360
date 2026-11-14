<?php

declare(strict_types=1);

namespace App\Features\Ai\Api;

interface AiBudget
{
    public function decide(
        string $operation,
        ?string $provider,
        ?string $model,
        ?float $estimatedCostUsd,
        bool $actorIsPrivileged = false,
    ): AiBudgetDecision;
}
