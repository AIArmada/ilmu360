<?php

declare(strict_types=1);

namespace App\Shared\Signals;

use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;

interface SignalIngestion
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TrackedProperty $trackedProperty, array $payload, bool $trusted): SignalEvent;
}
