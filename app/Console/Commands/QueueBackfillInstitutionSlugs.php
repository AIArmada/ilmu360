<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillInstitutionSlugs;
use Illuminate\Console\Command;

class QueueBackfillInstitutionSlugs extends Command
{
    protected $signature = 'institutions:queue-slug-backfill';

    protected $description = 'Queue regeneration of institution slugs using the geographic slug format.';

    public function handle(): int
    {
        BackfillInstitutionSlugs::dispatch();

        $this->info('Queued institution slug backfill job.');

        return self::SUCCESS;
    }
}
