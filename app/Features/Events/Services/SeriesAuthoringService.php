<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Actions\Series\DeleteSeriesAction;
use App\Features\Events\Actions\Series\SaveSeriesAction;
use App\Features\Events\Api\SeriesAuthoring;
use App\Features\Events\Models\Series;
use App\Platform\Models\SlugOrUuidResolver;
use App\Shared\Directory\FollowableSubject;

/**
 * Default implementation of the series authoring boundary.
 */
final readonly class SeriesAuthoringService implements SeriesAuthoring
{
    public function __construct(
        private SaveSeriesAction $saveSeries,
        private DeleteSeriesAction $deleteSeries,
        private SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    public function findFollowableSeries(string $identifier): ?FollowableSubject
    {
        $series = $this->slugOrUuidResolver->first(
            Series::query(),
            (new Series)->getTable().'.slug',
            $identifier,
        );

        if (! $series instanceof Series) {
            return null;
        }

        return new FollowableSubject(
            id: (string) $series->getKey(),
            slug: (string) $series->slug,
            type: 'series',
            morphType: $series->getMorphClass(),
            isPubliclyVisible: $series->visibility === 'public',
        );
    }

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

    /**
     * @return array<string, string>
     */
    public function titleOptions(): array
    {
        return Series::query()
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    public function shareIdentityBySlug(string $slug): ?array
    {
        $series = Series::query()->where('slug', $slug)->first(['id', 'slug', 'title']);

        if (! $series instanceof Series) {
            return null;
        }

        return [
            'id' => (string) $series->getKey(),
            'slug' => (string) $series->slug,
            'title' => (string) $series->title,
        ];
    }

    public function isPubliclyReachable(string $seriesId): bool
    {
        $series = Series::query()->find($seriesId);

        return $series instanceof Series && (string) $series->status === 'active';
    }
}
