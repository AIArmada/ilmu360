<?php

namespace App\Support\Search;

use AIArmada\CommerceSupport\Support\StringSimilarity;
use App\Contracts\PublicDiscoveryAdapter;
use App\Models\Institution;
use App\Models\InstitutionName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InstitutionSearchService implements PublicDiscoveryAdapter
{
    private const int PUBLIC_SEARCH_CACHE_TTL = 600;

    private const string PUBLIC_SEARCH_CACHE_VERSION_KEY = 'institution_search_public_version_v2';

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

        if ($this->shouldUseScoutSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
            try {
                return $this->applyScoutSearch($query, $normalizedSearch);
            } catch (\Throwable $exception) {
                $this->logScoutFallback('Institution Typesense search failed, falling back to database search', $exception, $normalizedSearch);
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

        return $this->scopedFuzzySearchIds($query, $normalizedSearch, $this->minimumFuzzyScore($normalizedSearch));
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    private function applyDatabaseSearch(Builder $query, string $normalizedSearch): Builder
    {
        $searchTokens = array_values(array_filter(explode(' ', $normalizedSearch), static fn (string $token): bool => $token !== ''));

        return $query->where(function (Builder $innerQuery) use ($normalizedSearch, $searchTokens): void {
            $innerQuery->searchNameOrNickname($normalizedSearch);

            if (count($searchTokens) < 2) {
                return;
            }

            $innerQuery->orWhere(function (Builder $tokenQuery) use ($searchTokens): void {
                foreach ($searchTokens as $token) {
                    if (mb_strlen($token) < 2) {
                        continue;
                    }

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

        $cacheKey = sprintf(
            'institution_search_public:%s:%s',
            $this->publicSearchCacheVersion(),
            md5($normalizedSearch),
        );

        /** @var list<string> $ids */
        $ids = Cache::remember($cacheKey, self::PUBLIC_SEARCH_CACHE_TTL, function () use ($normalizedSearch): array {
            if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
                try {
                    return $this->searchIdsWithScout($normalizedSearch, [
                        'filter_by' => 'status:=[verified,pending]',
                        'num_typos' => 0,
                    ]);
                } catch (\Throwable $exception) {
                    $this->logScoutFallback('Institution Typesense public search failed, falling back to database search', $exception, $normalizedSearch);
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
            ->whereIn('status', ['verified', 'pending'])
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

        $minimumScore = $this->minimumFuzzyScore($normalizedSearch);

        $cacheKey = sprintf(
            'institution_search_public_fuzzy:%s:%s',
            $this->publicSearchCacheVersion(),
            md5($normalizedSearch),
        );

        /** @var list<string> $ids */
        $ids = Cache::remember($cacheKey, self::PUBLIC_SEARCH_CACHE_TTL, function () use ($minimumScore, $normalizedSearch): array {
            if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
                try {
                    return $this->searchIdsWithScout($normalizedSearch, [
                        'filter_by' => 'status:=[verified,pending]',
                        'prioritize_exact_match' => true,
                    ]);
                } catch (\Throwable $exception) {
                    $this->logScoutFallback('Institution Typesense fuzzy search failed, falling back to database fuzzy search', $exception, $normalizedSearch);
                }
            }

            return $this->publicFuzzySearchIdsFromDatabase($normalizedSearch, $minimumScore);
        });

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function publicFuzzySearchIdsFromDatabase(string $normalizedSearch, float $minimumScore): array
    {
        return Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->with('names')
            ->select(['id', 'name'])
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateFilter($query, $normalizedSearch))
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateOrdering($query, $normalizedSearch))
            ->limit($this->typesenseResultLimit())
            ->get()
            ->map(function (Institution $institution) use ($normalizedSearch): array {
                $nameCandidates = array_values(array_filter([
                    StringSimilarity::normalize((string) $institution->name),
                    ...$institution->names->map(fn ($n) => StringSimilarity::normalize((string) $n->full_name))->all(),
                ], static fn (string $candidate): bool => $candidate !== ''));

                $scoreCandidates = [];

                foreach ($nameCandidates as $candidate) {
                    $scoreCandidates[] = $this->fuzzyScore($normalizedSearch, $candidate);

                    $tokens = array_values(array_filter(
                        explode(' ', $candidate),
                        static fn (string $token): bool => mb_strlen($token) >= 2
                    ));

                    foreach ($tokens as $token) {
                        $scoreCandidates[] = $this->fuzzyScore($normalizedSearch, $token);
                    }
                }

                return [
                    'id' => (string) $institution->id,
                    'score' => $scoreCandidates === [] ? 0.0 : max($scoreCandidates),
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
     * @return list<string>
     */
    private function scopedFuzzySearchIds(Builder $query, string $normalizedSearch, float $minimumScore): array
    {
        $model = $query->getModel();

        return (clone $query)
            ->reorder()
            ->select([
                $model->qualifyColumn($model->getKeyName()),
                $model->qualifyColumn('name'),
            ])
            ->with(['names'])
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateFilter($builder, $normalizedSearch))
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateOrdering($builder, $normalizedSearch))
            ->limit($this->typesenseResultLimit())
            ->get()
            ->map(function (Institution $institution) use ($normalizedSearch): array {
                $nameCandidates = array_values(array_filter([
                    StringSimilarity::normalize((string) $institution->name),
                    ...$institution->names->map(fn ($n) => StringSimilarity::normalize((string) $n->full_name))->all(),
                ], static fn (string $candidate): bool => $candidate !== ''));

                $scoreCandidates = [];

                foreach ($nameCandidates as $candidate) {
                    $scoreCandidates[] = $this->fuzzyScore($normalizedSearch, $candidate);

                    $tokens = array_values(array_filter(
                        explode(' ', $candidate),
                        static fn (string $token): bool => mb_strlen($token) >= 2,
                    ));

                    foreach ($tokens as $token) {
                        $scoreCandidates[] = $this->fuzzyScore($normalizedSearch, $token);
                    }
                }

                return [
                    'id' => (string) $institution->id,
                    'score' => $scoreCandidates === [] ? 0.0 : max($scoreCandidates),
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
        $patterns = $this->fuzzyCandidatePatterns($normalizedSearch);

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
     * @return list<string>
     */
    private function fuzzyCandidatePatterns(string $normalizedSearch): array
    {
        $tokens = array_values(array_unique(array_filter([
            $normalizedSearch,
            ...array_filter(explode(' ', $normalizedSearch), static fn (string $token): bool => mb_strlen($token) >= 3),
        ], static fn (string $token): bool => $token !== '')));

        $patterns = [];

        foreach ($tokens as $token) {
            foreach ($this->fuzzyPatternSources($token) as $patternSource) {
                $pattern = $this->fuzzySubsequencePattern($patternSource);

                if ($pattern !== null) {
                    $patterns[] = $pattern;
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @return list<string>
     */
    private function fuzzyPatternSources(string $value): array
    {
        $sources = [$value];

        if (str_contains($value, ' ') || mb_strlen($value) < 5) {
            return $sources;
        }

        return array_values(array_unique([
            ...$sources,
            ...$this->fuzzyOmissionVariants($value),
        ]));
    }

    /**
     * @return list<string>
     */
    private function fuzzyOmissionVariants(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($characters) || count($characters) < 2) {
            return [];
        }

        $variants = [];

        foreach (array_keys($characters) as $index) {
            $variantCharacters = $characters;
            unset($variantCharacters[$index]);

            $variant = implode('', $variantCharacters);

            if ($variant !== '') {
                $variants[] = $variant;
            }
        }

        return array_values(array_unique($variants));
    }

    private function fuzzySubsequencePattern(string $value): ?string
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($characters) || $characters === []) {
            return null;
        }

        return '%'.implode('%', $characters).'%';
    }

    protected function shouldUseScoutSearch(): bool
    {
        return in_array($this->scoutDriver(), ['typesense', 'database'], true);
    }

    protected function shouldUseTypesenseSearch(): bool
    {
        return $this->scoutDriver() === 'typesense';
    }

    /**
     * @param  Builder<Institution>  $query
     * @return Builder<Institution>
     */
    protected function applyScoutSearch(Builder $query, string $search): Builder
    {
        $ids = $this->searchIdsWithScout($search, [
            'num_typos' => 0,
        ]);

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
        if ($this->scoutDriver() === 'database') {
            return Institution::search($search)
                ->query(fn (Builder $query): Builder => $query->limit($this->typesenseResultLimit()))
                ->get()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $rawResults = Institution::search($search)
            ->options([
                'query_by' => 'display_name,name,nicknames,search_text',
                'per_page' => $this->typesenseResultLimit(),
                ...$options,
            ])
            ->raw();

        /** @var array<int, array<string, mixed>> $hits */
        $hits = is_array($rawResults) && is_array($rawResults['hits'] ?? null)
            ? $rawResults['hits']
            : [];

        return collect($hits)
            ->pluck('document.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    protected function typesenseResultLimit(): int
    {
        return max(50, (int) (config('scout.typesense.max_total_results') ?? 250));
    }

    protected function logScoutFallback(string $message, \Throwable $exception, string $search): void
    {
        Log::warning($message, [
            'error' => $exception->getMessage(),
            'search' => $search,
        ]);
    }

    protected function scoutDriver(): string
    {
        return (string) config('scout.driver');
    }

    public function bustPublicSearchCache(): void
    {
        Cache::put(
            self::PUBLIC_SEARCH_CACHE_VERSION_KEY,
            $this->publicSearchCacheVersion() + 1,
            now()->addDays(30),
        );
    }

    public function normalizedSearch(string $search): ?string
    {
        $normalized = StringSimilarity::normalize($search);

        return $normalized === '' ? null : $normalized;
    }

    private function publicSearchCacheVersion(): int
    {
        return (int) Cache::get(self::PUBLIC_SEARCH_CACHE_VERSION_KEY, 1);
    }

    private function fuzzyScore(string $search, string $candidate): float
    {
        if (! FuzzySearchPolicy::isComparable($search, $candidate, $this->maximumFuzzyDistance($search))) {
            return 0.0;
        }

        return StringSimilarity::score($search, $candidate);
    }

    private function minimumFuzzyScore(string $search): float
    {
        return mb_strlen($search) >= 6 ? 0.80 : 0.70;
    }

    private function maximumFuzzyDistance(string $search): int
    {
        return mb_strlen($search) >= 5 ? 2 : 1;
    }
}
