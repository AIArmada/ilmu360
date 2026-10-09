<?php

declare(strict_types=1);

namespace App\Jobs\ShareTracking;

use App\Support\Share\CommittedShareSignalProducer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Produces the ONE trusted committed-share signal for a committed
 * outbound share. Dispatched after commit by the touchpoint observer,
 * so a Signals outage retries this job and can never roll back the
 * already-committed share. Unique-until-processing per touchpoint
 * collapses duplicate dispatches; the semantic idempotency key makes
 * every retry apply once.
 */
final class ProduceCommittedShareSignalJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $touchpointId,
    ) {}

    public function uniqueId(): string
    {
        return 'committed-share-signal:'.$this->touchpointId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function handle(CommittedShareSignalProducer $producer): void
    {
        $producer->produce($this->touchpointId);
    }
}
