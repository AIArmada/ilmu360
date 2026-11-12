<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Public person-search cache version boundary.
 *
 * Root search adapters read and advance the same versioned cache namespace
 * that Directory uses when the person search projection changes.
 */
interface PersonSearchCacheVersion
{
    public function version(): int;

    public function bump(): void;
}
