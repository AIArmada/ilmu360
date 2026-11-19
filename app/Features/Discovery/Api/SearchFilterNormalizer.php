<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

interface SearchFilterNormalizer
{
    /** @return array<string, mixed>|null */
    public function normalizeForStorage(mixed $filters): ?array;
}
