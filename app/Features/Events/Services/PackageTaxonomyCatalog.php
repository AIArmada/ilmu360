<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Events\Contracts\EventTaxonomyHierarchy;
use App\Features\Events\Api\TaxonomyCatalog;

final readonly class PackageTaxonomyCatalog implements TaxonomyCatalog
{
    public function __construct(private EventTaxonomyHierarchy $hierarchy) {}

    public function terms(string $taxonomyCode, ?string $search = null, int $limit = 50): array
    {
        return $this->hierarchy->searchTerms($taxonomyCode, $search, $limit);
    }
}
