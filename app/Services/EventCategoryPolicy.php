<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;

final readonly class EventCategoryPolicy implements EventCategoryPolicyResolver
{
    public function __construct(private EventCategoryCatalog $catalog) {}

    public function requiresSpeaker(array $termIds): bool
    {
        return $this->hasFlag($termIds, 'requires_speaker');
    }

    public function requiresPhysicalDelivery(array $termIds): bool
    {
        return $this->hasFlag($termIds, 'requires_physical_delivery');
    }

    /** @param list<string> $termIds */
    private function hasFlag(array $termIds, string $flag): bool
    {
        foreach ($this->catalog->terms($this->catalog->descendantIds($termIds)) as $term) {
            $metadata = is_array($term->metadata) ? $term->metadata : [];
            if (($metadata[$flag] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
