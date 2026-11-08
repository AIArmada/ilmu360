<?php

declare(strict_types=1);

namespace App\Shared\Discovery;

interface SearchFilterCatalog
{
    /** @return list<string> */
    public function allowedValues(string $filterKey): array;

    public function label(string $filterKey, string $value): ?string;

    /**
     * @param  list<mixed>  $ids
     * @return array<int, string>
     */
    public function validCategoryIds(array $ids): array;
}
