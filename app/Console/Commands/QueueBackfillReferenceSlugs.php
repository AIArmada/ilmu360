<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillReferenceSlugs;
use Illuminate\Console\Command;

class QueueBackfillReferenceSlugs extends Command
{
    protected $signature = 'references:queue-slug-backfill';

    protected $description = 'Queue regeneration of reference slugs using the sequential title slug format.';

    public function handle(): int
    {
        BackfillReferenceSlugs::dispatch();

        $this->info('Queued reference slug backfill job.');

        return self::SUCCESS;
    }
}
