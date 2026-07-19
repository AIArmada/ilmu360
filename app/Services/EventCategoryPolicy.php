<?php

declare(strict_types=1);

namespace App\Services;

use AIArmada\Events\Models\EventTermPolicy;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;

final class EventCategoryPolicy implements EventCategoryPolicyResolver
{
    /** @var array<string, list<string>> */
    private array $enabledPoliciesByTermSet = [];

    public function __construct(private readonly EventCategoryCatalog $catalog) {}

    public function requiresSpeaker(array $termIds): bool
    {
        return in_array('requires_speaker', $this->enabledPolicies($termIds), true);
    }

    public function requiresPhysicalDelivery(array $termIds): bool
    {
        return in_array('requires_physical_delivery', $this->enabledPolicies($termIds), true);
    }

    /**
     * @param  list<string>  $termIds
     * @return list<string>
     */
    private function enabledPolicies(array $termIds): array
    {
        $normalizedTermIds = array_values(array_unique(array_map(strval(...), $termIds)));
        sort($normalizedTermIds);
        $cacheKey = implode('|', $normalizedTermIds);

        if (array_key_exists($cacheKey, $this->enabledPoliciesByTermSet)) {
            return $this->enabledPoliciesByTermSet[$cacheKey];
        }

        $descendantIds = $this->catalog->descendantIds($normalizedTermIds);

        if ($descendantIds === []) {
            return $this->enabledPoliciesByTermSet[$cacheKey] = [];
        }

        return $this->enabledPoliciesByTermSet[$cacheKey] = EventTermPolicy::query()
            ->whereIn('event_term_id', $descendantIds)
            ->whereIn('policy_code', ['requires_speaker', 'requires_physical_delivery'])
            ->where('is_enabled', true)
            ->pluck('policy_code')
            ->map(strval(...))
            ->unique()
            ->values()
            ->all();
    }
}
