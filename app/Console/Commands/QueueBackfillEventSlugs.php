<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillEventSlugs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Queue regeneration of event slugs using the dated slug format.')]
#[Signature('events:queue-slug-backfill')]
class QueueBackfillEventSlugs extends Command
{
    public function handle(): int
    {
        BackfillEventSlugs::dispatch();

        $this->info('Queued event slug backfill job.');

        return self::SUCCESS;
    }
}
