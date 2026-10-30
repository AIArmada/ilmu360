<?php

declare(strict_types=1);

namespace App\Console\Commands\ShareTracking;

use App\Services\ShareTracking\AffiliatesShareTrackingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('share:prune-guest-profiles {--days= : Retention in days before an unclaimed guest profile may be pruned} {--limit=1000 : Maximum profiles to inspect per run} {--dry-run : Report eligible profiles without deleting them}')]
#[Description('Prune abandoned anonymous share profiles past retention, keeping claims, conversions, and published short links intact.')]
class PruneGuestShareProfilesCommand extends Command
{
    public function handle(AffiliatesShareTrackingService $shareTrackingService): int
    {
        $days = (int) ($this->option('days') ?: config('dawah-share.guest_profile.retention_days', 90));

        $result = $shareTrackingService->pruneAbandonedGuestProfiles(
            retentionDays: $days,
            limit: (int) $this->option('limit'),
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->info(sprintf(
            '%sScanned %d guest profile(s), pruned %d, skipped %d.',
            $this->option('dry-run') ? 'Dry run: ' : '',
            $result['scanned'],
            $result['pruned'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
