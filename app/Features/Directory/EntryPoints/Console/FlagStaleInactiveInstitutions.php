<?php

declare(strict_types=1);

namespace App\Features\Directory\EntryPoints\Console;

use App\Features\Directory\Actions\Institutions\FlagStaleInactiveInstitutionsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Flag long-inactive institutions for manual review (dry run by default)')]
#[Signature('institutions:flag-stale-inactive
                            {--days=180 : Flag institutions inactive for at least this many days}
                            {--apply : Record the stale flag (default is a dry run)}')]
class FlagStaleInactiveInstitutions extends Command
{
    public function handle(FlagStaleInactiveInstitutionsAction $action): int
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

        if (! (bool) $this->option('apply')) {
            $count = $action->count($threshold);

            $this->info("Dry run: {$count} inactive institution(s) would be flagged (inactive_at <= {$threshold->toIso8601String()}).");
            $this->comment('Re-run with --apply to record the flag.');

            return self::SUCCESS;
        }

        $flagged = $action->handle($threshold);

        $this->info("Flagged {$flagged} inactive institution(s) for manual review.");

        return self::SUCCESS;
    }
}
