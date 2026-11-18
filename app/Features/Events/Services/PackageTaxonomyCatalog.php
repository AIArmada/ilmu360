<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Features\Events\Api\TaxonomyCatalog;

final readonly class PackageTaxonomyCatalog implements TaxonomyCatalog
{
    public function terms(string $taxonomyCode, ?string $search = null, int $limit = 50): array
    {
        $taxonomy = EventTaxonomy::query()
            ->where('code', $taxonomyCode)
            ->where('is_active', true)
            ->first();

        if ($taxonomy === null) {
            return [];
        }

        $query = EventTerm::query()
            ->where('event_taxonomy_id', $taxonomy->getKey())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $query->whereLike('name', '%'.$normalizedSearch.'%');
        }

        return $query
            ->limit($limit)
            ->get(['id', 'name', 'code'])
            ->map(fn (EventTerm $term): array => [
                'id' => (string) $term->id,
                'label' => (string) $term->name,
                'code' => (string) $term->code,
            ])
            ->all();
    }
}
