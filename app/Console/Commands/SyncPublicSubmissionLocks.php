<?php

namespace App\Console\Commands;

use App\Support\Submission\PublicSubmissionLockService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Auto-reopen locked institution/person submissions when credibility conditions are no longer met')]
#[Signature('app:sync-public-submission-locks')]
class SyncPublicSubmissionLocks extends Command
{
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
