<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reference;
use App\Platform\Search\AbstractIndexToScout;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Import all searchable references into the configured Scout driver')]
#[Signature('search:index-references
                            {--fresh : Flush the current Scout index before importing}
                            {--chunk=500 : Number of records to process per chunk}')]
class IndexReferencesToTypesense extends AbstractIndexToScout
{
    protected function searchableModel(): string
    {
        return Reference::class;
    }

    protected function searchableLabel(): string
    {
        return 'reference';
    }
}
