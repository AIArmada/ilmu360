<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

use Closure;

/**
 * Public person-search cache boundary.
 *
 * Root search adapters use the same versioned result cache that Directory
 * invalidates when the person search projection changes.
 */
interface PersonSearchCache
{
    public function bumpPublicSearchVersion(): void;

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    public function rememberPublicSearchIds(string $normalizedSearch, Closure $resolve): array;

    /**
     * @param  Closure(): list<string>  $resolve
     * @return list<string>
     */
    public function rememberPublicFuzzySearchIds(string $normalizedSearch, Closure $resolve): array;
}
