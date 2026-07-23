<?php

namespace App\Console\Commands;

use App\Support\Search\PersonSearchService;
use Illuminate\Console\Command;

class ReindexPersonSearch extends Command
{
    protected $signature = 'speakers:reindex-search
                            {--chunk=100 : Number of speakers to process per chunk}';

    protected $description = 'Rebuild the speaker search index and searchable names for all speakers.';

    public function __construct(
        private readonly PersonSearchService $personSearchService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->personSearchService->searchIndexSchemaReady()) {
            $this->error('Speaker search schema is not ready. Run the migrations first.');

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $processed = $this->personSearchService->reindexAll($chunkSize);

        $this->info(sprintf('Reindexed %d speaker search record(s).', $processed));

        return self::SUCCESS;
    }
}
