<?php

namespace App\Console\Commands;

use App\Support\Submission\PublicSubmissionLockService;
use Illuminate\Console\Command;

class SyncPublicSubmissionLocks extends Command
{
    protected $signature = 'app:sync-public-submission-locks';

    protected $description = 'Auto-reopen locked institution/person submissions when credibility conditions are no longer met';

    public function handle(PublicSubmissionLockService $lockService): int
    {
        $result = $lockService->sweepLockedEntities();

        $this->info(sprintf(
            'Public submission locks synced. Institutions reopened: %d, Persons reopened: %d.',
            $result['institutions_reopened'],
            $result['persons_reopened'],
        ));

        return self::SUCCESS;
    }
}
