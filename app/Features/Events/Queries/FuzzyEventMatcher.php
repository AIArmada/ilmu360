<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\Events\Models\Event;
use App\Platform\Search\DatabaseFuzzyMatcher;
use Illuminate\Database\Eloquent\Builder;

final readonly class FuzzyEventMatcher
{
    public function __construct(
        private DatabaseFuzzyMatcher $fuzzyMatcher,
    ) {}

    /**
     * @param  Builder<Event>  $queryBuilder
     * @return Builder<Event>
     */
    public function applyFuzzyTitleCandidateFilter(Builder $queryBuilder, string $normalizedSearch): Builder
    {
        return $this->fuzzyMatcher
            ->applyColumnsFilter($queryBuilder, ['events.title'], $normalizedSearch)
            ->limit($this->fuzzyCandidateLimit());
    }

    /**
     * @param  Builder<Event>  $queryBuilder
     * @return Builder<Event>
     */
    public function applyFuzzyTitleCandidateOrdering(Builder $queryBuilder, string $normalizedSearch): Builder
    {
        return $queryBuilder
            ->orderByRaw(
                "case when lower(coalesce(events.title, '')) = ? then 0 when lower(coalesce(events.title, '')) like ? then 1 when lower(coalesce(events.title, '')) like ? then 2 else 3 end",
                [$normalizedSearch, $normalizedSearch.'%', '%'.$normalizedSearch.'%']
            )
            ->orderByRaw("length(coalesce(events.title, ''))")
            ->orderBy('events.title')
            ->orderBy('starts_at')
            ->orderBy('events.id');
    }

    public function fuzzyCandidateLimit(): int
    {
        return 250;
    }

    public function eventSimilarityScore(string $normalizedSearch, Event $event): float
    {
        $title = trim((string) $event->title);

        if ($title === '') {
            return 0.0;
        }

        return $this->fuzzyMatcher->bestScore(
            $normalizedSearch,
            [$title],
            usePolicy: false,
        );
    }
}
