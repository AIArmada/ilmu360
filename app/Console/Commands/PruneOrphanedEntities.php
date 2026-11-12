<?php

namespace App\Console\Commands;

use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Api\VenueWorkflow;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Venue;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Description('Prune pending institutions, persons, and venues with no associated events after a threshold period')]
#[Signature('app:prune-orphaned-entities
                            {--hours=48 : Hours after which orphaned entities are pruned}
                            {--dry-run : Show what would be deleted without deleting}')]
class PruneOrphanedEntities extends Command
{
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
                ->whereNotIn('id', function ($query) {
                    $involvementsTable = config('events.database.tables.event_involvements', 'event_involvements');
                    $query->select('involveable_id')
                        ->from($involvementsTable)
                        ->where('involveable_type', (new Institution)->getMorphClass())
                        ->where('role_code', 'organizer')
                        ->where('is_primary', true);
                }),
            $dryRun,
            fn (array $institutionIds): int => app(InstitutionWorkflow::class)->delete($institutionIds),
        );

        $totalPruned += $this->pruneModel(
            'Persons',
            Person::query()
                ->where('status', 'pending')
                ->where('created_at', '<', $threshold)
                ->whereDoesntHave('events'),
            $dryRun,
            fn (array $personIds): int => app(PersonWorkflow::class)->delete($personIds),
        );

        $totalPruned += $this->pruneModel(
            'Venues',
            Venue::query()
                ->where('status', 'pending')
                ->where('created_at', '<', $threshold)
                ->whereDoesntHave('events'),
            $dryRun,
            fn (array $venueIds): int => app(VenueWorkflow::class)->delete($venueIds),
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
     * @param  (Closure(list<string>): int)|null  $deleteUsing
     */
    private function pruneModel(string $label, Builder $query, bool $dryRun, ?Closure $deleteUsing = null): int
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

        if ($deleteUsing instanceof Closure) {
            $model = $query->getModel();
            /** @var list<string> $ids */
            $ids = (clone $query)->pluck($model->getQualifiedKeyName())
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
            $deleteUsing($ids);
        } else {
            $query->each(fn (Model $model): ?bool => $model->delete());
        }

        $this->line("  {$label}: {$count} pruned.");

        return $count;
    }
}
