<?php

declare(strict_types=1);

namespace App\Shared\Discovery;

interface SearchFilterLabels
{
    public function label(string $filterKey, string $id): ?string;
}
