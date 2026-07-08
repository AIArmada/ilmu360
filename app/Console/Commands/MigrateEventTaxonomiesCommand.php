<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Events\SyncEventTaxonomiesAction;
use App\Models\Event;
use Illuminate\Console\Command;

class MigrateEventTaxonomiesCommand extends Command
{
    protected $signature = 'events:migrate-taxonomies';

    protected $description = 'Migrate existing Spatie tags to package taxonomies for all events';

    public function handle(SyncEventTaxonomiesAction $syncAction): int
    {
        $total = Event::query()->count();

        if ($total === 0) {
            $this->warn('No events found.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;

        Event::query()->chunk(100, function ($events) use ($syncAction, $bar, &$synced): void {
            foreach ($events as $event) {
                $synced += $syncAction->handle($event);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Migrated {$synced} taxonomies across {$total} events.");

        return self::SUCCESS;
    }
}
