<?php

declare(strict_types=1);

namespace App\Features\Institutions\Services;

use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Api\InstitutionSearchCache;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Models\InstitutionName;
use App\Platform\Search\DatabaseFuzzyMatcher;
use App\Platform\Search\ScoutRawHitExtractor;
use App\Shared\Contracts\SearchEngineResolver;
use App\Shared\Discovery\PublicDiscoveryAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstitutionSearchService implements \App\Features\Institutions\Api\InstitutionSearch, PublicDiscoveryAdapter
{
    public function __construct(
        private readonly InstitutionSearchCache $cache,
        private readonly SearchEngineResolver $engineResolver,
        private readonly ScoutRawHitExtractor $hitExtractor,
        private readonly DatabaseFuzzyMatcher $fuzzyMatcher,
    ) {}

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    public function applySearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->engineResolver->isRemoteEngineAvailable()) {
            try {
                return $this->applyScoutSearch($query, $normalizedSearch);
            } catch (Throwable $exception) {
                $this->logScoutFallback('Institution Scout search failed, falling back to database search', $exception, $normalizedSearch);
            }
        }

        return $this->applyDatabaseSearch($query, $normalizedSearch);
    }

    /**
     * Apply the same public search strategy as {@see publicSearchIds()}
     * directly to a scoped query.
     *
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    public function applyPublicSearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->engineResolver->isRemoteEngineAvailable()) {
            try {
                $options = $this->engineResolver->usesTypesense()
                    ? ['filter_by' => 'status:=[verified,pending]']
                    : [];

                return $this->applyScoutSearch($query, $normalizedSearch, $options);
            } catch (Throwable $exception) {
                $this->logScoutFallback('Institution Scout public search failed, falling back to database search', $exception, $normalizedSearch);
            }
        }

        return $this->applyDatabaseSearch($query, $normalizedSearch);
    }

    /**
     * @param  Builder<Institution>  $query
     * @return list<string>
     */
    public function scopedSearchIds(Builder $query, string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        $model = $query->getModel();
        $keyColumn = $model->qualifyColumn($model->getKeyName());

        $ids = (clone $query)
            ->select($keyColumn)
            ->tap(fn (Builder $builder): Builder => $this->applySearch($builder, $normalizedSearch))
            ->orderBy($model->qualifyColumn('name'))
            ->pluck($keyColumn)
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($ids !== [] || mb_strlen($normalizedSearch) < 3) {
            return $ids;
        }

        return $this->scopedFuzzySearchIds($query, $normalizedSearch);
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    private function applyDatabaseSearch(Builder $query, string $normalizedSearch): Builder
    {
        $searchTokens = $this->fuzzyMatcher->tokens($normalizedSearch, 2);

        return $query->where(function (Builder $innerQuery) use ($normalizedSearch, $searchTokens): void {
            $innerQuery->searchNameOrNickname($normalizedSearch);

            if (count($searchTokens) < 2) {
                return;
            }

            $innerQuery->orWhere(function (Builder $tokenQuery) use ($searchTokens): void {
                foreach ($searchTokens as $token) {
                    $tokenQuery->where(function (Builder $tokenMatchQuery) use ($token): void {
                        $tokenMatchQuery->searchNameOrNickname($token);
                    });
                }
            });
        });
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

        /** @var list<string> $ids */
        $ids = $this->cache->rememberPublicSearchIds($normalizedSearch, function () use ($normalizedSearch): array {
            if ($this->engineResolver->isRemoteEngineAvailable()) {
                try {
                    $options = $this->engineResolver->usesTypesense()
                        ? [
                            'filter_by' => 'status:=[verified,pending]',
                            'num_typos' => 0,
                        ]
                        : [];

                    return $this->searchIdsWithScout($normalizedSearch, $options);
                } catch (Throwable $exception) {
                    $this->logScoutFallback('Institution Scout public search failed, falling back to database search', $exception, $normalizedSearch);
                }
            }

            return $this->publicSearchIdsFromDatabase($normalizedSearch);
        });

        return $ids;
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
    private function publicSearchIdsFromDatabase(string $normalizedSearch): array
    {
        return Institution::query()
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
            ->select('institutions.id')
            ->tap(fn (Builder $query): Builder => $this->applyDatabaseSearch($query, $normalizedSearch))
            ->orderBy('name')
            ->pluck('institutions.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
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

        /** @var list<string> $ids */
        $ids = $this->cache->rememberPublicFuzzySearchIds($normalizedSearch, function () use ($normalizedSearch): array {
            if ($this->engineResolver->isRemoteEngineAvailable()) {
                try {
                    $options = $this->engineResolver->usesTypesense()
                        ? [
                            'filter_by' => 'status:=[verified,pending]',
                            'prioritize_exact_match' => true,
                        ]
                        : [];

                    return $this->searchIdsWithScout($normalizedSearch, $options);
                } catch (Throwable $exception) {
                    $this->logScoutFallback('Institution Scout fuzzy search failed, falling back to database fuzzy search', $exception, $normalizedSearch);
                }
            }

            return $this->publicFuzzySearchIdsFromDatabase($normalizedSearch);
        });

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function publicFuzzySearchIdsFromDatabase(string $normalizedSearch): array
    {
        $rows = Institution::query()
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
            ->with('names')
            ->select(['id', 'name'])
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateFilter($query, $normalizedSearch))
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateOrdering($query, $normalizedSearch))
            ->limit($this->engineResolver->resultLimit())
            ->get();

        return $this->scoreFuzzyCandidates($rows, $normalizedSearch);
    }

    /**
     * @param  Builder<Institution>  $query
     * @return list<string>
     */
    private function scopedFuzzySearchIds(Builder $query, string $normalizedSearch): array
    {
        $model = $query->getModel();

        $rows = (clone $query)
            ->reorder()
            ->select([
                $model->qualifyColumn($model->getKeyName()),
                $model->qualifyColumn('name'),
            ])
            ->with(['names'])
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateFilter($builder, $normalizedSearch))
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateOrdering($builder, $normalizedSearch))
            ->limit($this->engineResolver->resultLimit())
            ->get();

        return $this->scoreFuzzyCandidates($rows, $normalizedSearch);
    }

    /**
     * @param  EloquentCollection<int, Institution>  $rows
     * @return list<string>
     */
    private function scoreFuzzyCandidates(EloquentCollection $rows, string $normalizedSearch): array
    {
        $minimumScore = $this->fuzzyMatcher->minimumScore($normalizedSearch);
        $maximumDistance = $this->fuzzyMatcher->maximumDistance($normalizedSearch);

        return $rows
            ->map(function (Institution $institution) use ($normalizedSearch, $maximumDistance): array {
                $candidates = [
                    (string) $institution->name,
                    ...$institution->names->map(static fn (InstitutionName $name): string => (string) $name->full_name)->all(),
                ];

                return [
                    'id' => (string) $institution->id,
                    'score' => $this->fuzzyMatcher->bestScore($normalizedSearch, $candidates, $maximumDistance),
                ];
            })
            ->filter(static fn (array $candidate): bool => $candidate['score'] >= $minimumScore)
            ->sortByDesc('score')
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    private function applyFuzzyCandidateFilter(Builder $query, string $normalizedSearch): Builder
    {
        if ($this->fuzzyMatcher->isPostgres($query)) {
            $threshold = $this->fuzzyMatcher->pgTrgmThreshold();

            return $query->where(function (Builder $candidateQuery) use ($normalizedSearch, $threshold): void {
                $this->fuzzyMatcher->orWherePgTrgm($candidateQuery, 'institutions.name', $normalizedSearch, $threshold);

                $candidateQuery->orWhereHas('names', function (Builder $nameQuery) use ($normalizedSearch, $threshold): void {
                    $nameQuery->where(function (Builder $innerNameQuery) use ($normalizedSearch, $threshold): void {
                        $this->fuzzyMatcher->orWherePgTrgm($innerNameQuery, 'full_name', $normalizedSearch, $threshold);
                    });
                });
            });
        }

        $patterns = $this->fuzzyMatcher->candidatePatterns($normalizedSearch);

        if ($patterns === []) {
            return $query;
        }

        return $query->where(function (Builder $candidateQuery) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $candidateQuery
                    ->orWhereLike('institutions.name', $pattern)
                    ->orWhereHas('names', fn (Builder $nameQuery) => $nameQuery->whereLike('full_name', $pattern));
            }
        });
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    private function applyFuzzyCandidateOrdering(Builder $query, string $normalizedSearch): Builder
    {
        $nameQuery = InstitutionName::query()
            ->select('full_name')
            ->whereColumn('institution_id', 'institutions.id')
            ->where('is_primary', true)
            ->limit(1);

        $nameQuerySql = $nameQuery->toSql();
        $nameQueryBindings = $nameQuery->getBindings();

        return $query
            ->orderByRaw(
                "case when lower(coalesce(institutions.name, '')) = ? or lower(coalesce(({$nameQuerySql}), '')) = ? then 0 when lower(coalesce(institutions.name, '')) like ? or lower(coalesce(({$nameQuerySql}), '')) like ? then 1 else 2 end",
                [
                    $normalizedSearch,
                    ...$nameQueryBindings,
                    $normalizedSearch,
                    $normalizedSearch.'%',
                    ...$nameQueryBindings,
                    $normalizedSearch.'%',
                ]
            )
            ->orderByRaw("length(coalesce(institutions.name, ''))")
            ->orderBy('institutions.name')
            ->orderBy('institutions.id');
    }

    /**
     * @param  Builder<Institution>  $query
     * @param  array<string, mixed>  $options
     * @return Builder<Institution>
     */
    protected function applyScoutSearch(Builder $query, string $search, array $options = []): Builder
    {
        $ids = $this->searchIdsWithScout(
            $search,
            $this->engineResolver->usesTypesense()
                ? ['num_typos' => 0, ...$options]
                : $options,
        );

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->getModel()->qualifyColumn('id'), $ids);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    protected function searchIdsWithScout(string $search, array $options = []): array
    {
        $limit = $this->engineResolver->resultLimit();

        if ($this->engineResolver->activeDriver() === 'database') {
            return Institution::search($search)
                ->query(static fn (Builder $query): Builder => $query->limit($limit))
                ->get()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $scoutOptions = $this->engineResolver->usesTypesense()
            ? [
                'query_by' => 'display_name,name,nicknames,search_text',
                'per_page' => $limit,
                ...$options,
            ]
            : ['per_page' => $limit, ...$options];

        $rawResults = Institution::search($search)
            ->options($scoutOptions)
            ->raw();

        return $this->hitExtractor->extractIds($rawResults);
    }

    protected function logScoutFallback(string $message, Throwable $exception, string $search): void
    {
        Log::warning($message, [
            'error' => $exception->getMessage(),
            'search' => $search,
        ]);
    }

    public function bustPublicSearchCache(): void
    {
        $this->cache->bumpPublicSearchVersion();
    }

    public function normalizedSearch(string $search): ?string
    {
        return $this->fuzzyMatcher->normalize($search);
    }
}
