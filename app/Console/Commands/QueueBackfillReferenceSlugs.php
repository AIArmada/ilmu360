<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BackfillReferenceSlugs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Queue regeneration of reference slugs using the sequential title slug format.')]
#[Signature('references:queue-slug-backfill')]
class QueueBackfillReferenceSlugs extends Command
{
    public function handle(): int
    {
        BackfillReferenceSlugs::dispatch();

        $this->info('Queued reference slug backfill job.');

        return self::SUCCESS;
    }
}
