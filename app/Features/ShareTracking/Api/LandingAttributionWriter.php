<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Creates landing attributions through the configured Affiliates model.
 */
interface LandingAttributionWriter
{
    public function createLandingAttribution(CreateLandingAttributionData $data): LandingAttributionReceipt;
}
