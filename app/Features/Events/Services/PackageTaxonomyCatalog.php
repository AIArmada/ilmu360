<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Events\Contracts\EventTaxonomyHierarchy;
use App\Features\Events\Api\TaxonomyCatalog;
use App\Shared\Cache\SelectionCatalog;

final readonly class PackageTaxonomyCatalog implements TaxonomyCatalog
{
    public function __construct(
        private EventTaxonomyHierarchy $hierarchy,
        private SelectionCatalog $selectionCatalog,
    ) {}

    public function terms(string $taxonomyCode, ?string $search = null, int $limit = 50): array
    {
        if (mb_trim((string) $search) === '') {
            return collect($this->selectionCatalog->taxonomyTermRows($taxonomyCode))
                ->take(max(1, $limit))
                ->map(static fn (array $term): array => [
                    'id' => $term['id'],
                    'label' => $term['name'],
                    'code' => $term['code'],
                ])
                ->values()
                ->all();
        }

        return $this->hierarchy->searchTerms($taxonomyCode, $search, $limit);
    }
}
