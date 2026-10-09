<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PruneShareCards extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:prune-share-cards
                            {--days= : Days after which cached card files are pruned (defaults to share-cards.prune_after_days)}
                            {--dry-run : Show what would be deleted without deleting}';

    /**
     * @var string
     */
    protected $description = 'Prune stale generated share-card files. Safe at any time: card URLs are revision-pinned and regenerate on demand.';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('share-cards.prune_after_days', 30);

        if ($days < 1) {
            $this->error('Prune threshold must be at least one day.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk((string) config('share-cards.disk', 'public'));
        $threshold = Carbon::now()->subDays($days)->getTimestamp();

        $this->info("Pruning share-card files older than {$days} days...");

        if ($dryRun) {
            $this->warn('DRY RUN — no files will be deleted.');
        }

        $pruned = 0;

        foreach ($disk->files('share-cards') as $file) {
            // Crashed renders leave share-card-<rev>.tmp-<pid> partials
            // (no .png suffix); they prune under the same threshold,
            // which no live render can outlast.
            $isCard = str_ends_with($file, '.png');
            $isStaleTmp = str_contains($file, '.tmp-');

            if (! $isCard && ! $isStaleTmp) {
                continue;
            }

            $modified = $disk->lastModified($file);

            if ($modified > $threshold) {
                continue;
            }

            if ($dryRun) {
                $this->line("Would delete: {$file}");
            } else {
                $disk->delete($file);
            }

            $pruned++;
        }

        $this->info($dryRun ? "Would prune {$pruned} share-card file(s)." : "Pruned {$pruned} share-card file(s).");

        return self::SUCCESS;
    }
}
