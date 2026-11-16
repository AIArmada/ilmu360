<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Models\Institution;

use App\Shared\Directory\Enums\InstitutionStatus;
use Carbon\CarbonImmutable;

/**
 * Flag long-inactive institutions for manual review.
 */
final readonly class FlagStaleInactiveInstitutionsAction
{
    /**
     * @return int Number of rows flagged.
     */
    public function handle(CarbonImmutable $threshold): int
    {
        $now = now();

        return Institution::query()
            ->where('status', InstitutionStatus::Inactive->value)
            ->whereNotNull('inactive_at')
            ->where('inactive_at', '<=', $threshold)
            ->whereNull('stale_inactive_flagged_at')
            ->update([
                'stale_inactive_flagged_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function count(CarbonImmutable $threshold): int
    {
        return Institution::query()
            ->where('status', InstitutionStatus::Inactive->value)
            ->whereNotNull('inactive_at')
            ->where('inactive_at', '<=', $threshold)
            ->whereNull('stale_inactive_flagged_at')
            ->count();
    }
}
