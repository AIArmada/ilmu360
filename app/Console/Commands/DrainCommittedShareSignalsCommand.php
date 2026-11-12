<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Share\CommittedShareSignalReader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Project committed-share Signals into the cohort projection (durable cursor, crash-safe).')]
#[Signature('signals:drain-committed-shares {--batch=25 : Maximum signal events to project}')]
final class DrainCommittedShareSignalsCommand extends Command
{
    public function handle(CommittedShareSignalReader $reader): int
    {
        $drained = $reader->drain(max(1, (int) $this->option('batch')));

        $this->info("Signals processed: {$drained['processed']}; projected {$drained['projected']}; failed {$drained['failed']}.");

        return self::SUCCESS;
    }
}
