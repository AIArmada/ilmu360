<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Console\Commands;

use App\Features\Events\Api\EventShareCardCache;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Description('Prune stale generated share-card files. Safe at any time: card URLs are revision-pinned and regenerate on demand.')]
#[Signature('app:prune-share-cards
                            {--days= : Days after which cached card files are pruned (defaults to share-cards.prune_after_days)}
                            {--dry-run : Show what would be deleted without deleting}')]
class PruneShareCards extends Command
{
    public function handle(EventShareCardCache $shareCardCache): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('share-cards.prune_after_days', 30);

        if ($days < 1) {
            $this->error('Prune threshold must be at least one day.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $threshold = Carbon::now()->subDays($days)->getTimestamp();

        $this->info("Pruning share-card files older than {$days} days...");

        if ($dryRun) {
            $this->warn('DRY RUN — no files will be deleted.');
        }

        $prunedFiles = $shareCardCache->pruneOlderThan($threshold, $dryRun);

        if ($dryRun) {
            foreach ($prunedFiles->files as $file) {
                $this->line("Would delete: {$file}");
            }
        }

        $this->info($dryRun
            ? "Would prune {$prunedFiles->count()} share-card file(s)."
            : "Pruned {$prunedFiles->count()} share-card file(s).");

        return self::SUCCESS;
    }
}
