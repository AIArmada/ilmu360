<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillPersonSlugs;
use Illuminate\Console\Command;

class QueueBackfillPersonSlugs extends Command
{
    protected $signature = 'speakers:queue-slug-backfill';

    protected $description = 'Queue regeneration of speaker slugs using the displayed-name and country slug format.';

    public function handle(): int
    {
        BackfillPersonSlugs::dispatch();

        $this->info('Queued speaker slug backfill job.');

        return self::SUCCESS;
    }
}
