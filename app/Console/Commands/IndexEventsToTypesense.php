<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Platform\Search\AbstractIndexToScout;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Import all searchable events into the configured Scout driver')]
#[Signature('search:index-events
                            {--fresh : Drop existing index and recreate}
                            {--chunk=500 : Number of records to process per chunk}')]
class IndexEventsToTypesense extends AbstractIndexToScout
{
    protected function searchableModel(): string
    {
        return Event::class;
    }

    protected function searchableLabel(): string
    {
        return 'event';
    }
}
