<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Events\Contracts\EventTaxonomyHierarchy;
use AIArmada\Events\Models\EventTerm;
use App\Features\Events\Api\EventCategoryCatalog as EventCategoryCatalogContract;
use App\Shared\Cache\SelectionCatalogCache;

final readonly class EventCategoryCatalog implements EventCategoryCatalogContract
{
    public const string TAXONOMY_CODE = 'event_category';

    public function __construct(private EventTaxonomyHierarchy $hierarchy) {}

    public function taxonomyId(): ?string
    {
        $taxonomy = $this->hierarchy->taxonomy(self::TAXONOMY_CODE);

        return $taxonomy?->is_active ? (string) $taxonomy->getKey() : null;
    }

    public function tree(): array
    {
        return $this->hierarchy->tree(self::TAXONOMY_CODE);
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return app(SelectionCatalogCache::class)->eventCategoryOptions();
    }

    /**
     * @param  list<mixed>  $termIds
     * @return array<int, string>
     */
    public function validateTermIds(array $termIds): array
    {
        return $this->hierarchy->minimalTermIds(self::TAXONOMY_CODE, $termIds);
    }

    /**
     * @param  list<mixed>  $termIds
     * @return array<int, string>
     */
    public function validTermIds(array $termIds): array
    {
        return $this->hierarchy->validTermIds(self::TAXONOMY_CODE, $termIds);
    }

    /**
     * @param  list<string>  $termIds
     * @return array<int, string>
     */
    public function descendantIds(array $termIds): array
    {
        return $this->hierarchy->descendantIds(self::TAXONOMY_CODE, $termIds);
    }

    /**
     * @param  list<string>  $termIds
     * @return list<string>
     */
    public function termNames(array $termIds): array
    {
        $terms = $this->hierarchy->terms(self::TAXONOMY_CODE);
        $selected = array_fill_keys($this->validTermIds($termIds), true);

        return $terms
            ->filter(fn (EventTerm $term): bool => isset($selected[(string) $term->getKey()]))
            ->map(fn (EventTerm $term): string => (string) $term->name)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $termIds
     * @return list<string>
     */
    public function termCodes(array $termIds): array
    {
        $selected = array_fill_keys($this->validTermIds($termIds), true);

        return $this->hierarchy->terms(self::TAXONOMY_CODE)
            ->filter(fn (EventTerm $term): bool => isset($selected[(string) $term->getKey()]))
            ->map(fn (EventTerm $term): string => (string) $term->code)
            ->values()
            ->all();
    }
}
