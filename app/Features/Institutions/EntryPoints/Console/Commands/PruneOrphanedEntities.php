<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Console\Commands;

use App\Features\Institutions\Actions\PruneOrphanedInstitutionsAction;
use App\Features\Persons\Api\PruneOrphanedPersons;
use App\Features\Places\Api\PruneOrphanedVenues;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Prune pending institutions, persons, and venues with no associated events after a threshold period')]
#[Signature('app:prune-orphaned-entities
                            {--hours=48 : Hours after which orphaned entities are pruned}
                            {--dry-run : Show what would be deleted without deleting}')]
class PruneOrphanedEntities extends Command
{
    public function handle(
        PruneOrphanedInstitutionsAction $pruneInstitutions,
        PruneOrphanedPersons $prunePersons,
        PruneOrphanedVenues $pruneVenues,
    ): int {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Pruning orphaned entities older than {$hours} hours...");

        if ($dryRun) {
            $this->warn('DRY RUN: no records will be deleted.');
        }

        $totalPruned = 0;

        foreach ([
            'Institutions' => $pruneInstitutions,
            'Persons' => $prunePersons,
            'Venues' => $pruneVenues,
        ] as $label => $action) {
            $result = $action($hours, $dryRun);

            if ($result['found'] === 0) {
                $this->line("  {$label}: 0 orphaned records found.");

                continue;
            }

            if ($dryRun) {
                $this->line("  {$label}: {$result['found']} would be pruned.");

                continue;
            }

            $totalPruned += $result['pruned'];
            $this->line("  {$label}: {$result['pruned']} pruned.");
        }

        $this->newLine();
        $this->info("Total pruned: {$totalPruned} entities.");

        return self::SUCCESS;
    }
}
