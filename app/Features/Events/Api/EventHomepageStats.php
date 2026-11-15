<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface EventHomepageStats
{
    public function activeEventCount(): int;

    public function forgetCachedCount(): void;
}
