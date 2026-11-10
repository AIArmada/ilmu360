<?php

namespace App\Jobs;

use App\Actions\Events\Authoring\GenerateEventSlugAction;
use App\Features\Directory\Api\PersonWorkflow;
use App\Platform\Cache\PublicListingsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillPersonSlugs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(
        GenerateEventSlugAction $generateEventSlugAction,
        PersonWorkflow $personWorkflow,
        PublicListingsCache $publicListingsCache,
    ): void {
        $updatedPersonIds = $personWorkflow->backfillSlugs();

        foreach (array_values(array_unique($updatedPersonIds)) as $personId) {
            $generateEventSlugAction->syncEventSlugsForPersonId($personId);
        }

        $publicListingsCache->bustMajlisListing();
    }

    public function uniqueId(): string
    {
        return 'person-slug-backfill';
    }
}
