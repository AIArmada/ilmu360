<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Speaker;

class IndexSpeakersToTypesense extends AbstractIndexToScout
{
    protected $signature = 'search:index-speakers
                            {--fresh : Flush the current Scout index before importing}
                            {--chunk=500 : Number of records to process per chunk}';

    protected $description = 'Import all searchable speakers into the configured Scout driver';

    protected function searchableModel(): string
    {
        return Speaker::class;
    }

    protected function searchableLabel(): string
    {
        return 'speaker';
    }
}
