<?php

namespace App\Features\Persons\EntryPoints\Console\Commands;

use App\Features\Persons\Api\PersonSearchMaintenance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Rebuild the person search index and searchable names for all persons.')]
#[Signature('persons:reindex-search
                            {--chunk=100 : Number of persons to process per chunk}')]
class ReindexPersonSearch extends Command
{
    public function __construct(
        private readonly PersonSearchMaintenance $personSearchService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $processed = $this->personSearchService->reindexAll($chunkSize);

        $this->info(sprintf('Reindexed %d person search record(s).', $processed));

        return self::SUCCESS;
    }
}
