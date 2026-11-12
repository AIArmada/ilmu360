<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Institution;
use App\Platform\Search\AbstractIndexToScout;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Import all searchable institutions into the configured Scout driver')]
#[Signature('search:index-institutions
                            {--fresh : Flush the current Scout index before importing}
                            {--chunk=500 : Number of records to process per chunk}')]
class IndexInstitutionsToTypesense extends AbstractIndexToScout
{
    protected function searchableModel(): string
    {
        return Institution::class;
    }

    protected function searchableLabel(): string
    {
        return 'institution';
    }
}
