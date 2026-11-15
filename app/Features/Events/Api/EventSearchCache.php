<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Closure;

interface EventSearchCache
{
    /**
     * @param  Closure(): array{ids: list<string>, total: int}  $resolver
     */
    public function rememberDefaultSearch(Closure $resolver): DefaultEventSearchCacheResult;

    public function forgetDefaultSearch(): void;
}
