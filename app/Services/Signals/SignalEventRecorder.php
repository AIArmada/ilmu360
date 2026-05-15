<?php

declare(strict_types=1);

namespace App\Services\Signals;

use AIArmada\Signals\Actions\IngestSignalEvent;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;

class SignalEventRecorder
{
    public function __construct(
        private readonly IngestSignalEvent $ingestSignalEvent,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(TrackedProperty $trackedProperty, array $payload): SignalEvent
    {
        return $this->ingestSignalEvent->handle($trackedProperty, $payload);
    }
}
