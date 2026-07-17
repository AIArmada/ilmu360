<?php

namespace App\Console\Commands;

use App\Models\Event;

class IndexEventsToTypesense extends AbstractIndexToScout
{
    protected $signature = 'search:index-events
                            {--fresh : Drop existing index and recreate}
                            {--chunk=500 : Number of records to process per chunk}';

    protected $description = 'Import all searchable events into the configured Scout driver';

    protected function searchableModel(): string
    {
        return Event::class;
    }

    protected function searchableLabel(): string
    {
        return 'event';
    }
}
