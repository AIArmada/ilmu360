<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

interface PlaceCatalog
{
    /** @return list<array{id: string, label: string}> */
    public function venues(?string $search = null, int $limit = 50): array;

    /** @return list<array{id: string, label: string}> */
    public function spaces(?string $institutionId = null): array;
}
