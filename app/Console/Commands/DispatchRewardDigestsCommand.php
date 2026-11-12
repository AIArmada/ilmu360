<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Rewards\DispatchDueRewardDigestsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Dispatch due share gratitude digests.')]
#[Signature('rewards:dispatch-gratitude-digests {--batch=50 : Maximum due slots to attempt}')]
final class DispatchRewardDigestsCommand extends Command
{
    public function handle(DispatchDueRewardDigestsAction $dispatch): int
    {
        $outcome = $dispatch->handle(batch: max(1, (int) $this->option('batch')));

        $this->info("Digests dispatched: {$outcome['dispatched']}; reconciled {$outcome['reconciled']}; cancelled {$outcome['cancelled']}; skipped {$outcome['skipped']}.");

        return self::SUCCESS;
    }
}
