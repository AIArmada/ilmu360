<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Person;
use App\Platform\Search\AbstractIndexToScout;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Import all searchable persons into the configured Scout driver')]
#[Signature('search:index-persons
                            {--fresh : Flush the current Scout index before importing}
                            {--chunk=500 : Number of records to process per chunk}')]
class IndexPersonsToTypesense extends AbstractIndexToScout
{
    protected function searchableModel(): string
    {
        return Person::class;
    }

    protected function searchableLabel(): string
    {
        return 'person';
    }
}
