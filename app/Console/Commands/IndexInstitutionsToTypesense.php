<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Institution;

class IndexInstitutionsToTypesense extends AbstractIndexToScout
{
    protected $signature = 'search:index-institutions
                            {--fresh : Flush the current Scout index before importing}
                            {--chunk=500 : Number of records to process per chunk}';

    protected $description = 'Import all searchable institutions into the configured Scout driver';

    protected function searchableModel(): string
    {
        return Institution::class;
    }

    protected function searchableLabel(): string
    {
        return 'institution';
    }
}
