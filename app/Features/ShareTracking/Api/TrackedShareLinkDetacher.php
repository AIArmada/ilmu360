<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Detaches a share link's tracked redirect while preserving its destination. */
interface TrackedShareLinkDetacher
{
    public function detachTrackedShareLink(string $affiliateLinkId): void;
}
