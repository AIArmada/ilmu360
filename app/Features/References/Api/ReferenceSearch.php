<?php

declare(strict_types=1);

namespace App\Features\References\Api;

use App\Shared\Discovery\PublicDiscoveryAdapter;

/**
 * Public reference search boundary consumed by aggregate discovery.
 */
interface ReferenceSearch extends PublicDiscoveryAdapter
{
    /**
     * Resolve public reference ids for a search, falling back to fuzzy
     * matching exactly like the owner's directory search.
     *
     * @return list<string>
     */
    public function resolvedPublicSearchIds(string $search): array;
}
