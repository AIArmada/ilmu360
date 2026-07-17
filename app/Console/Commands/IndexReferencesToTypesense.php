<?php

namespace App\Console\Commands;

use App\Models\Reference;

class IndexReferencesToTypesense extends AbstractIndexToScout
{
    protected $signature = 'search:index-references
                            {--fresh : Flush the current Scout index before importing}
                            {--chunk=500 : Number of records to process per chunk}';

    protected $description = 'Import all searchable references into the configured Scout driver';

    protected function searchableModel(): string
    {
        return Reference::class;
    }

    protected function searchableLabel(): string
    {
        return 'reference';
    }
}
