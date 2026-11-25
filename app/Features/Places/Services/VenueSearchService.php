<?php

declare(strict_types=1);

namespace App\Features\Places\Services;

use App\Features\Places\Api\VenueSearch;
use App\Features\Places\Api\VenueSearchCache;
use App\Features\Places\Models\Venue;
use App\Platform\Search\DatabaseFuzzyMatcher;
use App\Shared\Contracts\SearchEngineResolver;
use App\Shared\Discovery\PublicDiscoveryAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class VenueSearchService implements PublicDiscoveryAdapter, VenueSearch
{
    public function __construct(
        private readonly VenueSearchCache $cache,
        private readonly SearchEngineResolver $engineResolver,
        private readonly DatabaseFuzzyMatcher $fuzzyMatcher,
    ) {}

    public function normalizedSearch(string $search): ?string
    {
        return $this->fuzzyMatcher->normalize($search);
    }

    /**
     * @param  Builder<Venue>  $query
     * @return Builder<Venue>
     */
    public function applyPublicSearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyDatabaseSearch($query, $normalizedSearch);
    }

    /**
     * @return list<string>
     */
    public function publicSearchIds(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        return $this->cache->rememberPublicSearchIds($normalizedSearch, fn (): array => Venue::query()
            ->active()
            ->where('status', 'verified')
            ->select('venues.id')
            ->tap(fn (Builder $query): Builder => $this->applyDatabaseSearch($query, $normalizedSearch))
            ->orderBy('venues.name')
            ->pluck('venues.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all());
    }

    /**
     * @return list<string>
     */
    public function resolvedPublicSearchIds(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        $ids = $this->publicSearchIds($normalizedSearch);

        if ($ids !== [] || mb_strlen($normalizedSearch) < 3) {
            return $ids;
        }

        return $this->publicFuzzySearchIds($normalizedSearch);
    }

    /**
     * @return list<string>
     */
    public function publicFuzzySearchIds(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        return $this->cache->rememberPublicFuzzySearchIds($normalizedSearch, function () use ($normalizedSearch): array {
            $rows = Venue::query()
                ->active()
                ->where('status', 'verified')
                ->select(['id', 'name', 'slug'])
                ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateFilter($query, $normalizedSearch))
                ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateOrdering($query, $normalizedSearch))
                ->limit($this->engineResolver->resultLimit())
                ->get();

            return $this->scoreFuzzyCandidates($rows, $normalizedSearch);
        });
    }

    public function bustPublicSearchCache(): void
    {
        $this->cache->bumpPublicSearchVersion();
    }

    /**
     * @param  Builder<Venue>  $query
     * @return Builder<Venue>
     */
    private function applyDatabaseSearch(Builder $query, string $normalizedSearch): Builder
    {
        $collapsedWildcardSearch = '%'.str_replace(' ', '%', $normalizedSearch).'%';
        $searchTokens = $this->fuzzyMatcher->tokens($normalizedSearch, 2);

        return $query->where(function (Builder $innerQuery) use ($normalizedSearch, $collapsedWildcardSearch, $searchTokens): void {
            $this->applySingleTermDatabaseSearch($innerQuery, $normalizedSearch, $collapsedWildcardSearch);

            if (count($searchTokens) < 2) {
                return;
            }

            $innerQuery->orWhere(function (Builder $tokenQuery) use ($searchTokens): void {
                foreach ($searchTokens as $token) {
                    $tokenQuery->where(function (Builder $singleTokenQuery) use ($token): void {
                        $this->applySingleTermDatabaseSearch($singleTokenQuery, $token, "%{$token}%");
                    });
                }
            });
        });
    }

    /**
     * @param  Builder<Venue>  $query
     */
    private function applySingleTermDatabaseSearch(Builder $query, string $term, string $wildcardTerm): void
    {
        $query
            ->whereLike('venues.name', "%{$term}%")
            ->when($wildcardTerm !== "%{$term}%", static fn (Builder $builder): Builder => $builder->orWhereLike('venues.name', $wildcardTerm))
            ->orWhereLike('venues.slug', "%{$term}%")
            ->orWhereLike('venues.description', "%{$term}%")
            ->when($wildcardTerm !== "%{$term}%", static fn (Builder $builder): Builder => $builder->orWhereLike('venues.description', $wildcardTerm))
            ->orWhereHas('addresses', static function (Builder $addressQuery) use ($term, $wildcardTerm): void {
                $addressQuery
                    ->whereLike('line1', "%{$term}%")
                    ->when($wildcardTerm !== "%{$term}%", static fn (Builder $builder): Builder => $builder->orWhereLike('line1', $wildcardTerm))
                    ->orWhereLike('line2', "%{$term}%")
                    ->orWhereLike('postcode', "%{$term}%")
                    ->orWhereLike('city', "%{$term}%")
                    ->orWhereLike('state', "%{$term}%")
                    ->orWhereLike('country', "%{$term}%");
            });
    }

    /**
     * @param  Builder<Venue>  $query
     * @return Builder<Venue>
     */
    private function applyFuzzyCandidateFilter(Builder $query, string $normalizedSearch): Builder
    {
        return $this->fuzzyMatcher->applyColumnsFilter(
            $query,
            ['venues.name', 'venues.slug', 'venues.description'],
            $normalizedSearch,
        );
    }

    /**
     * @param  Builder<Venue>  $query
     * @return Builder<Venue>
     */
    private function applyFuzzyCandidateOrdering(Builder $query, string $normalizedSearch): Builder
    {
        return $query
            ->orderByRaw(
                "case when lower(coalesce(venues.name, '')) = ? then 0 when lower(coalesce(venues.name, '')) like ? then 1 else 2 end",
                [$normalizedSearch, $normalizedSearch.'%'],
            )
            ->orderByRaw("length(coalesce(venues.name, ''))")
            ->orderBy('venues.name')
            ->orderBy('venues.id');
    }

    /**
     * @param  EloquentCollection<int, Venue>  $rows
     * @return list<string>
     */
    private function scoreFuzzyCandidates(EloquentCollection $rows, string $normalizedSearch): array
    {
        $minimumScore = $this->fuzzyMatcher->minimumScore($normalizedSearch);
        $maximumDistance = $this->fuzzyMatcher->maximumDistance($normalizedSearch);

        return $rows
            ->map(function (Venue $venue) use ($normalizedSearch, $maximumDistance): array {
                $candidates = [
                    (string) $venue->name,
                    str_replace('-', ' ', (string) $venue->slug),
                ];

                return [
                    'id' => (string) $venue->id,
                    'score' => $this->fuzzyMatcher->bestScore($normalizedSearch, $candidates, $maximumDistance),
                ];
            })
            ->filter(static fn (array $candidate): bool => $candidate['score'] >= $minimumScore)
            ->sortByDesc('score')
            ->pluck('id')
            ->values()
            ->all();
    }
}
