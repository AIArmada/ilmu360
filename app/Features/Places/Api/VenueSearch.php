<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

use App\Shared\Discovery\PublicDiscoveryAdapter;

/**
 * Public venue search boundary consumed by aggregate discovery.
 */
interface VenueSearch extends PublicDiscoveryAdapter
{
    /**
     * Resolve public venue ids for a search, falling back to fuzzy
     * matching exactly like the owner's directory search.
     *
     * @return list<string>
     */
    public function resolvedPublicSearchIds(string $search): array;
}
