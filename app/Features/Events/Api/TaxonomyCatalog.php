<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface TaxonomyCatalog
{
    /** @return list<array{id: string, label: string, code: string}> */
    public function terms(string $taxonomyCode, ?string $search = null, int $limit = 50): array;
}
