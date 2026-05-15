<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PruneOrphanedEntities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prune-orphaned-entities
                            {--hours=48 : Hours after which orphaned entities are pruned}
                            {--dry-run : Show what would be deleted without deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune pending institutions, speakers, and venues with no associated events after a threshold period';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');
        $threshold = Carbon::now()->subHours($hours);

        $this->info("Pruning orphaned entities older than {$hours} hours...");

        if ($dryRun) {
            $this->warn('DRY RUN — no records will be deleted.');
        }

        $totalPruned = 0;

        $totalPruned += $this->pruneModel(
            'Institutions',
            Institution::query()
                ->where('status', 'pending')
                ->where('created_at', '<', $threshold)
                ->whereDoesntHave('events')
                ->whereNotIn('id', function ($query) {
                    $query->select('organizer_id')
                        ->from('events')
                        ->where('organizer_type', Institution::class);
                }),
            $dryRun,
        );

        $totalPruned += $this->pruneModel(
            'Speakers',
            Speaker::query()
                ->where('status', 'pending')
                ->where('created_at', '<', $threshold)
                ->whereDoesntHave('events'),
            $dryRun,
        );

        $totalPruned += $this->pruneModel(
            'Venues',
            Venue::query()
                ->where('status', 'pending')
                ->where('created_at', '<', $threshold)
                ->whereDoesntHave('events'),
            $dryRun,
        );

        $this->newLine();
        $this->info("Total pruned: {$totalPruned} entities.");

        return self::SUCCESS;
    }

    /**
     * Prune orphaned records for a given model query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function pruneModel(string $label, Builder $query, bool $dryRun): int
    {
        $count = $query->count();

        if ($count === 0) {
            $this->line("  {$label}: 0 orphaned records found.");

            return 0;
        }

        if ($dryRun) {
            $this->line("  {$label}: {$count} would be pruned.");

            return 0;
        }

        $query->each(fn (Model $model): ?bool => $model->delete());

        $this->line("  {$label}: {$count} pruned.");

        return $count;
    }
}
