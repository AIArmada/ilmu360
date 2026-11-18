<?php

declare(strict_types=1);

namespace App\Features\References\Api;

interface ReferenceCatalog
{
    /** @return list<array{id: string, label: string}> */
    public function references(?string $search = null, int $limit = 50): array;

    /** @return list<array{id: string, label: string}> */
    public function authors(?string $search = null, int $limit = 50): array;
}
