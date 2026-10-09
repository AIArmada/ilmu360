<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Share\CommittedShareSignalReader;
use Illuminate\Console\Command;

final class DrainCommittedShareSignalsCommand extends Command
{
    protected $signature = 'signals:drain-committed-shares {--batch=25 : Maximum signal events to project}';

    protected $description = 'Project committed-share Signals into the cohort projection (durable cursor, crash-safe).';

    public function handle(CommittedShareSignalReader $reader): int
    {
        $drained = $reader->drain(max(1, (int) $this->option('batch')));

        $this->info("Signals processed: {$drained['processed']}; projected {$drained['projected']}; failed {$drained['failed']}.");

        return self::SUCCESS;
    }
}
