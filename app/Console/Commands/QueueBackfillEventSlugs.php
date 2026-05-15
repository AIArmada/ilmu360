<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillEventSlugs;
use Illuminate\Console\Command;

class QueueBackfillEventSlugs extends Command
{
    protected $signature = 'events:queue-slug-backfill';

    protected $description = 'Queue regeneration of event slugs using the dated slug format.';

    public function handle(): int
    {
        BackfillEventSlugs::dispatch();

        $this->info('Queued event slug backfill job.');

        return self::SUCCESS;
    }
}
