<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

interface PersonListingCacheVersion
{
    /** @return array{version: string} */
    public function current(): array;

    public function bump(): void;
}
