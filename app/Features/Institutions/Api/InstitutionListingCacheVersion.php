<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

interface InstitutionListingCacheVersion
{
    /** @return array{version: string} */
    public function current(): array;

    public function bump(): void;
}
