<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Persists visit touchpoints using scalar facts, without exposing affiliate models.
 */
interface VisitTouchpointWriter
{
    public function recordVisit(VisitTouchpointData $data): void;
}
