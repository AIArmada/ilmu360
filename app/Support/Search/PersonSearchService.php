<?php

namespace App\Support\Search;

use AIArmada\CommerceSupport\Support\StringSimilarity;
use App\Features\Directory\Api\PersonSearchCacheVersion;
use App\Features\Directory\Api\PersonWorkflow;
use App\Models\Person;
use App\Platform\Search\FuzzySearchPolicy;
use App\Platform\Search\TypesenseHealthCheckService;
use App\Shared\Directory\PersonSearchText;
use App\Shared\Discovery\PublicDiscoveryAdapter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PersonSearchService implements PublicDiscoveryAdapter
{
    private const int PUBLIC_SEARCH_CACHE_TTL = 600;

    public function __construct(
        private readonly ?PersonWorkflow $personWorkflow = null,
        private readonly ?PersonSearchCacheVersion $personSearchCacheVersion = null,
    ) {}

    public function buildSearchableName(
        ?string $name,
    ): string {
        return PersonSearchText::searchableName($name);
    }

    public function buildSearchableText(Person $person): string
    {
        return PersonSearchText::searchableText($person);
    }

    /**
     * @return list<string>
     */
    public function buildSearchTerms(
        ?string $name,
    ): array {
        return PersonSearchText::terms($name);
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public function applyIndexedSearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->shouldUseScoutSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
            try {
                return $this->applyScoutSearch($query, $normalizedSearch);
            } catch (\Throwable $exception) {
                $this->logScoutFallback('Person Typesense search failed, falling back to local search', $exception, $normalizedSearch);
            }
        }

        if (! $this->hasPersonSearchTermsTable()) {
            return $this->applyDatabaseNameSearch($query, $normalizedSearch);
        }

        return $this->applyIndexedSearchWithLocalIndex($query, $normalizedSearch);
    }

    /**
     * @param  Builder<Person>  $query
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
            ->tap(fn (Builder $builder): Builder => $this->applyIndexedSearch($builder, $normalizedSearch))
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
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    private function applyIndexedSearchWithLocalIndex(Builder $query, string $search): Builder
    {
        $searchTokens = $this->searchTokens($search);

        if ($searchTokens === []) {
            return $query->whereRaw('1 = 0');
        }

        $qualifiedPersonId = $query->getModel()->qualifyColumn('id');

        return $query->where(function (Builder $personQuery) use ($search, $searchTokens, $qualifiedPersonId): void {
            $personQuery->where(function (Builder $indexedQuery) use ($searchTokens, $qualifiedPersonId): void {
                foreach ($searchTokens as $token) {
                    $indexedQuery->whereExists(function ($termQuery) use ($qualifiedPersonId, $token): void {
                        $termQuery->selectRaw('1')
                            ->from('person_search_terms')
                            ->whereColumn('person_search_terms.person_id', $qualifiedPersonId)
                            ->whereLike('person_search_terms.term', '%'.$token.'%');
                    });
                }
            })->orWhere(function (Builder $unindexedQuery) use ($search, $qualifiedPersonId): void {
                $unindexedQuery->whereNotExists(function ($termQuery) use ($qualifiedPersonId): void {
                    $termQuery->selectRaw('1')
                        ->from('person_search_terms')
                        ->whereColumn('person_search_terms.person_id', $qualifiedPersonId);
                });

                $this->applyDatabaseNameSearch($unindexedQuery, $search);
            });
        });
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public function applyPublicCachedSearch(Builder $query, string $search): Builder
    {
        $ids = $this->publicSearchIds($search);

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->getModel()->qualifyColumn('id'), $ids);
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
            'person_search_public:%s:%s',
            $this->publicSearchCacheVersion(),
            md5($normalizedSearch),
        );

        /** @var list<string> $ids */
        $ids = Cache::remember($cacheKey, self::PUBLIC_SEARCH_CACHE_TTL, function () use ($normalizedSearch): array {
            $localIds = $this->publicSearchIdsFromLocalSearch($normalizedSearch);

            if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
                try {
                    $scoutIds = $this->searchIdsWithScout($normalizedSearch, [
                        'filter_by' => 'status:=[verified,pending]',
                        'num_typos' => 0,
                    ]);

                    return $this->mergeOrderedIds($scoutIds, $localIds);
                } catch (\Throwable $exception) {
                    $this->logScoutFallback('Person Typesense public search failed, falling back to local search', $exception, $normalizedSearch);
                }
            }

            return $localIds;
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
    private function publicSearchIdsFromLocalSearch(string $normalizedSearch): array
    {
        if (! $this->hasPersonSearchTermsTable()) {
            return Person::query()
                ->whereIn('status', ['verified', 'pending'])
                ->select('persons.id')
                ->tap(fn (Builder $query): Builder => $this->applyDatabaseNameSearch($query, $normalizedSearch))
                ->orderBy('family_name')
                ->orderBy('name')
                ->pluck('persons.id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        return Person::query()
            ->whereIn('status', ['verified', 'pending'])
            ->select('persons.id')
            ->tap(fn (Builder $query): Builder => $this->applyIndexedSearchWithLocalIndex($query, $normalizedSearch))
            ->orderBy('family_name')
            ->orderBy('name')
            ->pluck('persons.id')
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

        $cacheKey = sprintf(
            'person_search_public_fuzzy:%s:%s',
            $this->publicSearchCacheVersion(),
            md5($normalizedSearch),
        );

        /** @var list<string> $ids */
        $ids = Cache::remember($cacheKey, self::PUBLIC_SEARCH_CACHE_TTL, function () use ($normalizedSearch): array {
            if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
                try {
                    return $this->searchIdsWithScout($normalizedSearch, [
                        'filter_by' => 'status:=[verified,pending]',
                        'prioritize_exact_match' => true,
                    ]);
                } catch (\Throwable $exception) {
                    $this->logScoutFallback('Person Typesense fuzzy search failed, falling back to local fuzzy search', $exception, $normalizedSearch);
                }
            }

            $personQuery = Person::query()
                ->whereIn('status', ['verified', 'pending'])
                ->select(['id', 'name', 'middle_name', 'family_name'])
                ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateFilter($query, $normalizedSearch))
                ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateOrdering($query, $normalizedSearch))
                ->limit($this->typesenseResultLimit());

            if ($this->hasSearchableNameColumn()) {
                $personQuery->addSelect('searchable_name');
            } else {
                $personQuery->addSelect(['name']);
            }

            return $personQuery
                ->get()
                ->map(function (Person $person) use ($normalizedSearch): array {
                    $candidate = $this->personCandidateSearchableName($person);

                    if ($candidate === '') {
                        return ['id' => (string) $person->id, 'score' => 0.0];
                    }

                    $scoreCandidates = [
                        FuzzySearchPolicy::isComparable($normalizedSearch, $candidate)
                            ? StringSimilarity::score($normalizedSearch, $candidate)
                            : 0.0,
                    ];

                    $candidateTokens = array_values(array_filter(
                        explode(' ', $candidate),
                        static fn (string $token): bool => mb_strlen($token) >= 2
                    ));

                    foreach ($candidateTokens as $token) {
                        if (! $this->fuzzyTokenPasses($token, $normalizedSearch)) {
                            continue;
                        }

                        $scoreCandidates[] = FuzzySearchPolicy::isComparable($normalizedSearch, $token)
                            ? StringSimilarity::score($normalizedSearch, $token)
                            : 0.0;
                    }

                    return [
                        'id' => (string) $person->id,
                        'score' => max($scoreCandidates),
                    ];
                })
                ->filter(static fn (array $candidate): bool => $candidate['score'] >= 0.70)
                ->sortByDesc('score')
                ->pluck('id')
                ->values()
                ->all();
        });

        return $ids;
    }

    /**
     * @param  Builder<Person>  $query
     * @return list<string>
     */
    private function scopedFuzzySearchIds(Builder $query, string $normalizedSearch): array
    {
        $model = $query->getModel();
        $keyColumn = $model->qualifyColumn($model->getKeyName());

        $personQuery = (clone $query)
            ->reorder()
            ->select([
                $keyColumn,
                $model->qualifyColumn('name'),
                $model->qualifyColumn('middle_name'),
                $model->qualifyColumn('family_name'),
            ])
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateFilter($builder, $normalizedSearch))
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateOrdering($builder, $normalizedSearch))
            ->limit($this->typesenseResultLimit());

        if ($this->hasSearchableNameColumn()) {
            $personQuery->addSelect($model->qualifyColumn('searchable_name'));
        } else {
            $personQuery->addSelect([
                $model->qualifyColumn('name'),
            ]);
        }

        return $personQuery
            ->get()
            ->map(function (Person $person) use ($normalizedSearch): array {
                $candidate = $this->personCandidateSearchableName($person);

                if ($candidate === '') {
                    return ['id' => (string) $person->id, 'score' => 0.0];
                }

                $scoreCandidates = [
                    FuzzySearchPolicy::isComparable($normalizedSearch, $candidate)
                        ? StringSimilarity::score($normalizedSearch, $candidate)
                        : 0.0,
                ];

                $candidateTokens = array_values(array_filter(
                    explode(' ', $candidate),
                    static fn (string $token): bool => mb_strlen($token) >= 2,
                ));

                foreach ($candidateTokens as $token) {
                    if (! $this->fuzzyTokenPasses($token, $normalizedSearch)) {
                        continue;
                    }

                    $scoreCandidates[] = FuzzySearchPolicy::isComparable($normalizedSearch, $token)
                        ? StringSimilarity::score($normalizedSearch, $token)
                        : 0.0;
                }

                return [
                    'id' => (string) $person->id,
                    'score' => max($scoreCandidates),
                ];
            })
            ->filter(static fn (array $candidate): bool => $candidate['score'] >= 0.70)
            ->sortByDesc('score')
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    private function applyFuzzyCandidateFilter(Builder $query, string $normalizedSearch): Builder
    {
        $patterns = $this->fuzzyCandidatePatterns($normalizedSearch);

        if ($patterns === []) {
            return $query;
        }

        $columns = $this->hasSearchableNameColumn()
            ? ['persons.searchable_name', 'persons.name', 'persons.family_name']
            : ['persons.name', 'persons.family_name'];

        return $query->where(function (Builder $candidateQuery) use ($columns, $patterns): void {
            foreach ($patterns as $pattern) {
                foreach ($columns as $column) {
                    $candidateQuery->orWhereLike($column, $pattern);
                }
            }
        });
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    private function applyFuzzyCandidateOrdering(Builder $query, string $normalizedSearch): Builder
    {
        $primaryColumn = $this->hasSearchableNameColumn()
            ? 'persons.searchable_name'
            : 'persons.name';

        return $query
            ->orderByRaw(
                "case when lower(coalesce({$primaryColumn}, '')) = ? then 0 when lower(coalesce({$primaryColumn}, '')) like ? then 1 else 2 end",
                [$normalizedSearch, $normalizedSearch.'%']
            )
            ->orderByRaw("length(coalesce({$primaryColumn}, ''))")
            ->orderBy($primaryColumn)
            ->orderBy('persons.id');
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
     * @param  Builder<Person>  $query
     * @return Builder<Person>
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
            return Person::search($search)
                ->query(fn (Builder $query): Builder => $query->limit($this->typesenseResultLimit()))
                ->get()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $rawResults = Person::search($search)
            ->options([
                'query_by' => 'formatted_name,search_text,name',
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

    public function syncIndex(Person $person): void
    {
        $this->workflow()->syncSearchProjection((string) $person->getKey(), false);
    }

    public function syncPersonRecord(Person $person): void
    {
        $this->workflow()->syncSearchProjection((string) $person->getKey());
    }

    public function reindexAll(int $chunkSize = 100): int
    {
        return $this->workflow()->reindexSearch($chunkSize);
    }

    public function searchIndexSchemaReady(): bool
    {
        return $this->hasSearchableNameColumn() && $this->hasPersonSearchTermsTable();
    }

    public function purgeIndex(Person $person): void
    {
        $this->workflow()->purgeSearchProjection((string) $person->getKey(), false);
    }

    public function purgePersonRecord(Person $person): void
    {
        $this->workflow()->purgeSearchProjection((string) $person->getKey());
    }

    private function workflow(): PersonWorkflow
    {
        return $this->personWorkflow ?? app(PersonWorkflow::class);
    }

    public function bustPublicSearchCache(): void
    {
        $this->cacheVersion()->bump();
    }

    public function normalizedSearch(string $search): ?string
    {
        $normalized = StringSimilarity::normalize($search);

        return $normalized === '' ? null : $normalized;
    }

    private function publicSearchCacheVersion(): int
    {
        return $this->cacheVersion()->version();
    }

    private function cacheVersion(): PersonSearchCacheVersion
    {
        return $this->personSearchCacheVersion ?? app(PersonSearchCacheVersion::class);
    }

    /**
     * @return list<string>
     */
    private function searchTokens(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        /** @var list<string> $tokens */
        $tokens = collect(explode(' ', $normalizedSearch))
            ->filter(static fn (string $token): bool => mb_strlen($token) >= 2)
            ->unique()
            ->values()
            ->all();

        return $tokens;
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    private function applyDatabaseNameSearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return $query;
        }

        $collapsedSearch = preg_replace('/\s+/u', ' ', $normalizedSearch) ?? '';
        $collapsedWildcardSearch = '%'.str_replace(' ', '%', $collapsedSearch).'%';
        $searchTokens = array_values(array_filter(explode(' ', $collapsedSearch), static fn (string $token): bool => $token !== ''));

        $qualifiedPersonId = $query->getModel()->qualifyColumn('id');

        return $query->where(function (Builder $personQuery) use ($collapsedSearch, $collapsedWildcardSearch, $qualifiedPersonId, $searchTokens): void {
            $personQuery->where(function (Builder $nameQuery) use ($collapsedSearch, $collapsedWildcardSearch, $qualifiedPersonId): void {
                $nameQuery->whereLike('name', "%{$collapsedSearch}%")
                    ->orWhereLike('family_name', "%{$collapsedSearch}%")
                    ->orWhereLike(DB::raw("concat(coalesce(name, ''), ' ', coalesce(family_name, ''))"), $collapsedWildcardSearch)
                    ->orWhereExists(function ($alternateNameQuery) use ($collapsedSearch, $qualifiedPersonId): void {
                        $alternateNameQuery->selectRaw('1')
                            ->from('person_names')
                            ->whereColumn('person_names.person_id', $qualifiedPersonId)
                            ->whereLike('person_names.full_name', "%{$collapsedSearch}%");
                    });
            });

            foreach ($searchTokens as $token) {
                if (mb_strlen($token) < 2) {
                    continue;
                }

                $personQuery->orWhereLike('name', "%{$token}%")
                    ->orWhereLike('family_name', "%{$token}%")
                    ->orWhereExists(function ($alternateNameQuery) use ($qualifiedPersonId, $token): void {
                        $alternateNameQuery->selectRaw('1')
                            ->from('person_names')
                            ->whereColumn('person_names.person_id', $qualifiedPersonId)
                            ->whereLike('person_names.full_name', "%{$token}%");
                    });
            }
        });
    }

    /**
     * For short queries, a candidate token must contain the query as a substring.
     * This prevents over-matching like "ali" against the token "al" (e.g. "Al-Bakri").
     */
    private function fuzzyTokenPasses(string $token, string $query): bool
    {
        if (mb_strlen($query) <= 3) {
            return str_contains($token, $query);
        }

        return true;
    }

    private function personCandidateSearchableName(Person $person): string
    {
        $candidate = $person->searchable_name;

        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        return $this->buildSearchableName(
            $person->formatted_name,
        );
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

    private function hasSearchableNameColumn(): bool
    {
        return Schema::hasColumn('persons', 'searchable_name');
    }

    private function hasPersonSearchTermsTable(): bool
    {
        return Schema::hasTable('person_search_terms');
    }

    /**
     * @param  list<string>  $primaryIds
     * @param  list<string>  $secondaryIds
     * @return list<string>
     */
    private function mergeOrderedIds(array $primaryIds, array $secondaryIds): array
    {
        return collect([...$primaryIds, ...$secondaryIds])
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
    }
}
