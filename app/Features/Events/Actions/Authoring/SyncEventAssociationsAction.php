<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Models\Event as PackageEvent;
use App\Features\Events\Support\EventModel;

final readonly class SyncEventAssociationsAction
{
    /**
     * @param  \App\Features\Events\Models\Event  $event
     * @param  list<string>  $referenceIds
     */
    public function syncReferences(PackageEvent $event, array $referenceIds): void
    {
        $event->auditSync('references', $referenceIds, true, ['references.id', 'references.title']);
    }

    /**
     * @param  \App\Features\Events\Models\Event  $event
     * @param  list<string>  $seriesIds
     */
    public function syncSeries(PackageEvent $event, array $seriesIds): void
    {
        $series = EventModel::new('series');

        $event->auditSync('series', $seriesIds, true, [$series->qualifyColumn('id'), $series->qualifyColumn('title')]);
    }
}
