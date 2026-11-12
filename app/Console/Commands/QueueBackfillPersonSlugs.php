<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillPersonSlugs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Queue regeneration of person slugs using the displayed-name and country slug format.')]
#[Signature('persons:queue-slug-backfill')]
class QueueBackfillPersonSlugs extends Command
{
    public function handle(): int
    {
        BackfillPersonSlugs::dispatch();

        $this->info('Queued person slug backfill job.');

        return self::SUCCESS;
    }
}
