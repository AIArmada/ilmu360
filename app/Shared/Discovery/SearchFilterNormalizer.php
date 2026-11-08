<?php

declare(strict_types=1);

namespace App\Shared\Discovery;

interface SearchFilterNormalizer
{
    /** @return array<string, mixed>|null */
    public function normalizeForStorage(mixed $filters): ?array;
}
