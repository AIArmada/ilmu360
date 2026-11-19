<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

interface SearchFilterLabels
{
    public function label(string $filterKey, string $id): ?string;
}
