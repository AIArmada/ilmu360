<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

final readonly class ShareCardCachePruneResult
{
    /**
     * @param  list<string>  $files
     */
    public function __construct(public array $files) {}

    public function count(): int
    {
        return count($this->files);
    }
}
