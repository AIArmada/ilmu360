<?php

declare(strict_types=1);

namespace App\Shared\Signals;

use AIArmada\Signals\Features\Ingestion\Actions\IngestSignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;

final readonly class PackageSignalIngestion implements SignalIngestion
{
    public function __construct(private IngestSignalEvent $ingestSignalEvent) {}

    public function handle(TrackedProperty $trackedProperty, array $payload, bool $trusted): SignalEvent
    {
        return $this->ingestSignalEvent->handle($trackedProperty, $payload, $trusted);
    }
}
