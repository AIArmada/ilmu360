<?php

declare(strict_types=1);

namespace App\Platform\Search;

use App\Shared\Contracts\SearchEngineResolver;
use App\Shared\Contracts\SearchHealthCheck;

final readonly class ScoutSearchEngineResolver implements SearchEngineResolver
{
    public function __construct(
        private SearchHealthCheck $healthCheck,
    ) {}

    public function activeDriver(): string
    {
        $configured = config('discovery.search.driver');

        if (is_string($configured) && trim($configured) !== '') {
            return strtolower(trim($configured));
        }

        return strtolower(trim((string) config('scout.driver')));
    }

    public function usesScout(): bool
    {
        return in_array($this->activeDriver(), ['typesense', 'meilisearch', 'algolia', 'database'], true);
    }

    public function usesTypesense(): bool
    {
        return $this->activeDriver() === 'typesense';
    }

    public function isRemoteEngineAvailable(): bool
    {
        $driver = $this->activeDriver();

        if ($driver === 'typesense') {
            return $this->healthCheck->isAvailable();
        }

        return in_array($driver, ['meilisearch', 'algolia'], true);
    }

    public function resultLimit(): int
    {
        $configured = config('discovery.search.result_limit');

        if (is_numeric($configured)) {
            return max(50, (int) $configured);
        }

        return max(50, (int) (config('scout.typesense.max_total_results') ?? 250));
    }
}
