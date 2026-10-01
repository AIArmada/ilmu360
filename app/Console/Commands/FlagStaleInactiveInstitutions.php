<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class FlagStaleInactiveInstitutions extends Command
{
    /**
     * @var string
     */
    protected $signature = 'institutions:flag-stale-inactive
                            {--days=180 : Flag institutions inactive for at least this many days}
                            {--apply : Record the stale flag (default is a dry run)}';

    /**
     * @var string
     */
    protected $description = 'Flag long-inactive institutions for manual review (dry run by default)';

    public function handle(): int
    {
        $days = filter_var(
            $this->option('days'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($days === false) {
            $this->error('The --days option must be a positive integer.');

            return self::FAILURE;
        }

        $threshold = CarbonImmutable::now('UTC')->subDays($days);

        $flag = (bool) $this->option('apply');

        if (! $flag) {
            $count = $this->flaggableQuery($threshold)->count();

            $this->info("Dry run: {$count} inactive institution(s) would be flagged (inactive_at <= {$threshold->toIso8601String()}).");
            $this->comment('Re-run with --apply to record the flag.');

            return self::SUCCESS;
        }

        $now = now();
        $flagged = $this->flaggableQuery($threshold)->update([
            'stale_inactive_flagged_at' => $now,
            'updated_at' => $now,
        ]);

        $this->info("Flagged {$flagged} inactive institution(s) for manual review.");

        return self::SUCCESS;
    }

    /**
     * Still-Inactive rows whose dedicated inactive_at transition timestamp is
     * at or before the UTC threshold and which have not been flagged yet.
     * No fallback to published_at, updated_at, or last_state_change_at: rows
     * without inactive_at are never selected. Already-flagged rows are left
     * untouched so repeated applies are idempotent.
     *
     * @return Builder<Institution>
     */
    private function flaggableQuery(CarbonImmutable $threshold): Builder
    {
        return Institution::query()
            ->where('status', InstitutionStatus::Inactive->value)
            ->whereNotNull('inactive_at')
            ->where('inactive_at', '<=', $threshold)
            ->whereNull('stale_inactive_flagged_at');
    }
}
