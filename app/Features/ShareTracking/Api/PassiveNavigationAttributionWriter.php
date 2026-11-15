<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Refreshes the last-seen timestamps on a landing attribution during passive
 * navigation.
 */
interface PassiveNavigationAttributionWriter
{
    public function touchPassiveNavigation(string $attributionId): void;
}
