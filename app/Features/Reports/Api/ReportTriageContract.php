<?php

declare(strict_types=1);

namespace App\Features\Reports\Api;

use App\Shared\Auth\AccountActor;

interface ReportTriageContract
{
    public function canTriage(?AccountActor $actor): bool;

    /**
     * @return array<string, array{label: string, description: string, requires_resolution_note: bool}>
     */
    public function availableActions(string $status): array;
}
