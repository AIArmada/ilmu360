<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Actions\Series\DeleteSeriesAction;
use App\Features\Events\Actions\Series\SaveSeriesAction;
use App\Features\Events\Api\SeriesAuthoring;

/**
 * Default implementation of the series authoring boundary.
 */
final readonly class SeriesAuthoringService implements SeriesAuthoring
{
    public function __construct(
        private SaveSeriesAction $saveSeries,
        private DeleteSeriesAction $deleteSeries,
    ) {}

    public function save(array $data, ?string $seriesId = null): string
    {
        return (string) $this->saveSeries->handle($data, $seriesId)->getKey();
    }

    public function delete(string $seriesId): bool
    {
        return $this->deleteSeries->handle($seriesId);
    }

    public function deleteMany(array $seriesIds): int
    {
        $deleted = 0;

        foreach (array_values(array_unique($seriesIds)) as $seriesId) {
            if ($this->deleteSeries->handle($seriesId)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
