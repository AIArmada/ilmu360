<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

final readonly class DefaultEventSearchCacheResult
{
    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public array $ids,
        public int $total,
    ) {}
}
