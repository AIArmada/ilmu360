<?php

namespace App\Console\Commands\ShareTracking;

use App\Actions\ShareTracking\DispatchShareEffectReceiptsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('share:dispatch-pending-effects {--limit=50 : Maximum receipts to process}')]
#[Description('Dispatch pending share-outcome effect receipts, recovering work lost to a crash after commit before enqueue.')]
class DispatchShareEffectReceiptsCommand extends Command
{
    public function handle(DispatchShareEffectReceiptsAction $dispatch): int
    {
        $processed = $dispatch->handle((int) $this->option('limit'));

        $this->info("Processed {$processed} pending share effect receipt(s).");

        return self::SUCCESS;
    }
}
