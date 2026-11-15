<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Creates or reuses package-owned outbound-share attributions. */
interface OutboundShareAttributionWriter
{
    public function createOutboundShareAttribution(CreateOutboundShareAttributionData $data): OutboundShareAttributionReceipt;
}
