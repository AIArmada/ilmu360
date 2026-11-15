<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Records outbound-share touchpoints from scalar caller facts.
 */
interface OutboundShareTouchpointWriter
{
    public function recordOutboundShare(OutboundShareTouchpointData $data): void;
}
