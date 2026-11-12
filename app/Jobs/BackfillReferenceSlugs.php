<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Features\Directory\Api\ReferenceWorkflow;
use App\Platform\Cache\PublicListingsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillReferenceSlugs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(
        ReferenceWorkflow $referenceWorkflow,
        PublicListingsCache $publicListingsCache,
    ): void {
        $referenceWorkflow->backfillSlugs();

        $publicListingsCache->bustMajlisListing();
    }

    public function uniqueId(): string
    {
        return 'reference-slug-backfill';
    }
}
