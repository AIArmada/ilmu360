<?php

declare(strict_types=1);

namespace App\Features\References\Api;

interface ReferenceListingCacheVersion
{
    /**
     * @return array{version: string}
     */
    public function current(): array;

    public function bump(): void;
}
