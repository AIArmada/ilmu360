<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

use Closure;

/**
 * Public reference-search cache boundary.
 */
interface ReferenceSearchCache
{
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

    public function bumpPublicSearchVersion(): void;
}
