<?php

declare(strict_types=1);

namespace App\Features\Ai\Services;

use App\Features\Ai\Models\AiUsageLog;

class AiUsageLedger
{
    public function currentPeriodCostUsd(): float
    {
        return (float) AiUsageLog::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }
}
