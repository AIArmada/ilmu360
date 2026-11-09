<?php

declare(strict_types=1);

namespace App\Shared\Signals;

use AIArmada\Signals\Models\SignalEvent;
use Illuminate\Http\Request;

interface SignalRecorder
{
    /**
     * Record one product signal. Implementations own property allowlisting,
     * client-context enrichment and best-effort failure handling.
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $eventName,
        string $eventCategory,
        ?string $actorId = null,
        array $properties = [],
        ?Request $request = null,
        ?string $anonymousId = null,
    ): ?SignalEvent;
}
