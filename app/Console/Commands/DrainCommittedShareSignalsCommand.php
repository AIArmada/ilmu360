<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\ShareTracking\Api\CommittedShareSignals;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Project committed-share Signals into the cohort projection (durable cursor, crash-safe).')]
#[Signature('signals:drain-committed-shares {--batch=25 : Maximum signal events to project}')]
final class DrainCommittedShareSignalsCommand extends Command
{
    public function handle(CommittedShareSignals $signals): int
    {
        $drained = $signals->drain(max(1, (int) $this->option('batch')));

        $this->info("Signals processed: {$drained['processed']}; projected {$drained['projected']}; failed {$drained['failed']}.");

        return self::SUCCESS;
    }
}
