<?php

declare(strict_types=1);

namespace App\Features\Discovery\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class DiscoverySignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'search.') || str_starts_with($event, 'listing.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'search.executed' => ['interaction_type', 'surface', 'query', 'filter_keys', 'filters', 'result_count', 'saved_search_id'],
            'listing.filtered' => ['interaction_type', 'surface', 'filter_keys', 'filters', 'result_count', 'saved_search_id'],
        ];
    }
}
