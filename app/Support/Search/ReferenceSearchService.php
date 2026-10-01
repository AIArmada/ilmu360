<?php

namespace App\Support\Search;

use AIArmada\CommerceSupport\Support\ConnectionDriver;
use AIArmada\CommerceSupport\Support\StringSimilarity;
use AIArmada\References\Enums\ReferenceContributorRole;
use App\Contracts\PublicDiscoveryAdapter;
use App\Models\Person;
use App\Models\Reference;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferenceSearchService implements PublicDiscoveryAdapter
{
    private const int PUBLIC_SEARCH_CACHE_TTL = 600;

    private const string PUBLIC_SEARCH_CACHE_VERSION_KEY = 'reference_search_public_version_v2';

    private const string PUBLIC_TYPESENSE_FILTER = 'status:=[verified,pending] && published_at:>0';

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
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
                $this->logScoutFallback('Reference Typesense search failed, falling back to database search', $exception, $normalizedSearch);
            }
        }

        return $this->applyDatabaseSearch($query, $normalizedSearch);
    }

    /**
     * @param  Builder<Reference>  $query
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
            ->orderBy($model->qualifyColumn('title'))
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
     * @return list<string>
     */
    public function publicSearchIds(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        $cacheKey = sprintf(
            'reference_search_public:%s:%s',
            $this->publicSearchCacheVersion(),
            md5($normalizedSearch),
        );

        /** @var list<string> $ids */
        $ids = Cache::remember($cacheKey, self::PUBLIC_SEARCH_CACHE_TTL, function () use ($normalizedSearch): array {
            if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
                try {
                    return $this->searchIdsWithScout($normalizedSearch, [
                        'filter_by' => self::PUBLIC_TYPESENSE_FILTER,
                        'num_typos' => 0,
                    ]);
                } catch (\Throwable $exception) {
                    $this->logScoutFallback('Reference Typesense public search failed, falling back to database search', $exception, $normalizedSearch);
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
    public function publicFuzzySearchIds(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        $minimumScore = $this->minimumFuzzyScore($normalizedSearch);

        $cacheKey = sprintf(
            'reference_search_public_fuzzy:%s:%s',
            $this->publicSearchCacheVersion(),
            md5($normalizedSearch),
        );

        /** @var list<string> $ids */
        $ids = Cache::remember($cacheKey, self::PUBLIC_SEARCH_CACHE_TTL, function () use ($minimumScore, $normalizedSearch): array {
            if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
                try {
                    return $this->searchIdsWithScout($normalizedSearch, [
                        'filter_by' => self::PUBLIC_TYPESENSE_FILTER,
                        'prioritize_exact_match' => true,
                    ]);
                } catch (\Throwable $exception) {
                    $this->logScoutFallback('Reference Typesense fuzzy search failed, falling back to database fuzzy search', $exception, $normalizedSearch);
                }
            }

            return $this->publicFuzzySearchIdsFromDatabase($normalizedSearch, $minimumScore);
        });

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function publicSearchIdsFromDatabase(string $normalizedSearch): array
    {
        return Reference::query()
            ->active()
            ->select('references.id')
            ->tap(fn (Builder $query): Builder => $this->applyDatabaseSearch($query, $normalizedSearch))
            ->orderBy('references.title')
            ->pluck('references.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function publicFuzzySearchIdsFromDatabase(string $normalizedSearch, float $minimumScore): array
    {
        $rows = Reference::query()
            ->active()
            ->select(['id', 'title', 'parent_id', 'publisher', 'edition_label', 'edition_number', 'isbn', 'language', 'year'])
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateFilter($query, $normalizedSearch))
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateOrdering($query, $normalizedSearch))
            ->limit($this->typesenseResultLimit())
            ->get();

        return $this->scoreFuzzyCandidates($rows, $normalizedSearch, $minimumScore);
    }

    /**
     * @param  EloquentCollection<int, Reference>  $rows
     * @return list<string>
     */
    private function scoreFuzzyCandidates(EloquentCollection $rows, string $normalizedSearch, float $minimumScore): array
    {
        $authorNames = $this->inheritedAuthorNamesByReferenceId($rows);

        return $rows
            ->map(function (Reference $reference) use ($normalizedSearch, $authorNames): array {
                $candidates = array_values(array_filter([
                    StringSimilarity::normalize((string) $reference->title),
                    StringSimilarity::normalize($authorNames[(string) $reference->getKey()] ?? ''),
                    StringSimilarity::normalize((string) ($reference->publisher ?? '')),
                    StringSimilarity::normalize((string) ($reference->edition_label ?? '')),
                    StringSimilarity::normalize((string) ($reference->edition_number ?? '')),
                    StringSimilarity::normalize((string) ($reference->isbn ?? '')),
                    StringSimilarity::normalize((string) ($reference->language ?? '')),
                    StringSimilarity::normalize((string) ($reference->year ?? '')),

                ], static fn (string $candidate): bool => $candidate !== ''));

                $scoreCandidates = [];

                foreach ($candidates as $candidate) {
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
                    'id' => (string) $reference->id,
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
     * @param  Builder<Reference>  $query
     * @return list<string>
     */
    private function scopedFuzzySearchIds(Builder $query, string $normalizedSearch, float $minimumScore): array
    {
        $model = $query->getModel();

        $rows = (clone $query)
            ->reorder()
            ->select([
                $model->qualifyColumn($model->getKeyName()),
                $model->qualifyColumn('title'),
                $model->qualifyColumn('parent_id'),
                $model->qualifyColumn('publisher'),
                $model->qualifyColumn('edition_label'),
                $model->qualifyColumn('edition_number'),
                $model->qualifyColumn('isbn'),
                $model->qualifyColumn('language'),
                $model->qualifyColumn('year'),

            ])
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateFilter($builder, $normalizedSearch))
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateOrdering($builder, $normalizedSearch))
            ->limit($this->typesenseResultLimit())
            ->get();

        return $this->scoreFuzzyCandidates($rows, $normalizedSearch, $minimumScore);
    }

    /**
     * Author display names for fuzzy scoring, resolved through the owning
     * work (two hierarchy levels) in a fixed handful of queries.
     *
     * @param  EloquentCollection<int, Reference>  $rows
     * @return array<string, string>
     */
    private function inheritedAuthorNamesByReferenceId(EloquentCollection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $parentIds = $rows
            ->map(static fn (Reference $reference): ?string => $reference->parent_id !== null ? (string) $reference->parent_id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var array<string, string|null> $grandparentByParent */
        $grandparentByParent = $parentIds === []
            ? []
            : Reference::query()->whereKey($parentIds)
                ->pluck('parent_id', 'id')
                ->map(static fn (mixed $id): ?string => $id !== null ? (string) $id : null)
                ->all();

        $ownerByReference = [];

        foreach ($rows as $reference) {
            $referenceId = (string) $reference->getKey();
            $parentId = $reference->parent_id !== null ? (string) $reference->parent_id : null;
            $grandparentId = $parentId !== null ? ($grandparentByParent[$parentId] ?? null) : null;

            $ownerByReference[$referenceId] = $grandparentId ?? $parentId ?? $referenceId;
        }

        $namesByOwner = $this->authorNamesByOwnerId(array_values(array_unique($ownerByReference)));
        $namesByReference = [];

        foreach ($ownerByReference as $referenceId => $ownerId) {
            $namesByReference[$referenceId] = $namesByOwner[$ownerId] ?? '';
        }

        return $namesByReference;
    }

    /**
     * @param  list<string>  $ownerIds
     * @return array<string, string>
     */
    private function authorNamesByOwnerId(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $contributorsTable = (string) config(
            'references.database.tables.reference_contributors',
            'reference_contributors',
        );

        $rows = DB::table($contributorsTable.' as reference_author_link')
            ->join('persons as reference_author_person', 'reference_author_person.id', '=', 'reference_author_link.contributor_id')
            ->whereIn('reference_author_link.reference_id', $ownerIds)
            ->where('reference_author_link.role', ReferenceContributorRole::Author->value)
            ->where('reference_author_link.contributor_type', (new Person)->getMorphClass())
            ->orderBy('reference_author_link.contributor_id')
            ->get([
                'reference_author_link.reference_id',
                'reference_author_person.name',
                'reference_author_person.middle_name',
                'reference_author_person.family_name',
            ]);

        $namesByOwner = [];

        foreach ($rows as $row) {
            $name = trim(implode(' ', array_filter([
                (string) ($row->name ?? ''),
                (string) ($row->middle_name ?? ''),
                (string) ($row->family_name ?? ''),
            ], static fn (string $part): bool => $part !== '')));

            if ($name === '') {
                continue;
            }

            $ownerId = (string) $row->reference_id;
            $namesByOwner[$ownerId] = trim(($namesByOwner[$ownerId] ?? '').' '.$name);
        }

        return $namesByOwner;
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    private function applyDatabaseSearch(Builder $query, string $normalizedSearch): Builder
    {
        $collapsedWildcardSearch = '%'.str_replace(' ', '%', $normalizedSearch).'%';
        $searchTokens = array_values(array_filter(
            explode(' ', $normalizedSearch),
            static fn (string $token): bool => $token !== '' && (mb_strlen($token) >= 2 || ctype_digit($token))
        ));

        return $query->where(function (Builder $innerQuery) use ($normalizedSearch, $collapsedWildcardSearch, $searchTokens): void {
            $innerQuery
                ->whereLike('references.title', "%{$normalizedSearch}%")
                ->orWhereLike('references.title', $collapsedWildcardSearch)
                ->orWhereAuthorNameLike("%{$normalizedSearch}%")
                ->orWhereLike('references.publisher', "%{$normalizedSearch}%")
                ->orWhereLike('references.description', "%{$normalizedSearch}%")
                ->orWhereLike('references.edition_label', "%{$normalizedSearch}%")
                ->orWhereLike($this->numericSearchColumn($innerQuery, 'edition_number'), "%{$normalizedSearch}%")
                ->orWhereLike('references.isbn', "%{$normalizedSearch}%")
                ->orWhereLike('references.language', "%{$normalizedSearch}%")
                ->orWhereLike($this->numericSearchColumn($innerQuery, 'year'), "%{$normalizedSearch}%")
                ->orWherePartTextLike("%{$normalizedSearch}%");

            if (preg_match('/^(?:cetakan|edisi|edition)\s+(\d+)$/i', $normalizedSearch, $editionMatch) === 1) {
                $innerQuery->orWhere('references.edition_number', (int) $editionMatch[1]);
            }

            if (count($searchTokens) < 2) {
                return;
            }

            $innerQuery->orWhere(function (Builder $tokenQuery) use ($searchTokens): void {
                foreach ($searchTokens as $token) {
                    $tokenQuery->where(function (Builder $singleTokenQuery) use ($token): void {
                        $singleTokenQuery
                            ->whereLike('references.title', "%{$token}%")
                            ->orWhereAuthorNameLike("%{$token}%")
                            ->orWhereLike('references.publisher', "%{$token}%")
                            ->orWhereLike('references.description', "%{$token}%")
                            ->orWhereLike('references.edition_label', "%{$token}%")
                            ->orWhereLike($this->numericSearchColumn($singleTokenQuery, 'edition_number'), "%{$token}%")
                            ->orWhereLike('references.isbn', "%{$token}%")
                            ->orWhereLike('references.language', "%{$token}%")
                            ->orWhereLike($this->numericSearchColumn($singleTokenQuery, 'year'), "%{$token}%")
                            ->orWherePartTextLike("%{$token}%");
                    });
                }
            });
        });
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
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
                    ->orWhereLike('references.title', $pattern)
                    ->orWhereAuthorNameLike($pattern)
                    ->orWhereLike('references.publisher', $pattern)
                    ->orWhereLike('references.edition_label', $pattern)
                    ->orWhereLike('references.isbn', $pattern)
                    ->orWhereLike('references.language', $pattern)
                    ->orWhereLike($this->numericSearchColumn($candidateQuery, 'edition_number'), $pattern);
            }
        });
    }

    /**
     * @param  Builder<Reference>  $query
     */
    private function numericSearchColumn(Builder $query, string $column): ExpressionContract
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn($column));
        $cast = ConnectionDriver::name($query->getConnection()) === 'mysql' ? 'CHAR' : 'TEXT';

        return new Expression('CAST('.$wrapped.' AS '.$cast.')');
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    private function applyFuzzyCandidateOrdering(Builder $query, string $normalizedSearch): Builder
    {
        $wrappedTitleColumn = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn('title'));

        return $query
            ->orderByRaw(
                "case when lower(coalesce({$wrappedTitleColumn}, '')) = ? then 0 when lower(coalesce({$wrappedTitleColumn}, '')) like ? then 1 else 2 end",
                [$normalizedSearch, $normalizedSearch.'%'],
            )
            ->orderByRaw("length(coalesce({$wrappedTitleColumn}, ''))")
            ->orderBy('references.title')
            ->orderBy('references.id');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    protected function searchIdsWithScout(string $search, array $options = []): array
    {
        if ($this->scoutDriver() === 'database') {
            return Reference::search($search)
                ->query(fn (Builder $query): Builder => $query->limit($this->typesenseResultLimit()))
                ->get()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $rawResults = Reference::search($search)
            ->options([
                'query_by' => 'title,authors,search_text',
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

    protected function shouldUseScoutSearch(): bool
    {
        return in_array($this->scoutDriver(), ['typesense', 'database'], true);
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
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

    protected function shouldUseTypesenseSearch(): bool
    {
        return $this->scoutDriver() === 'typesense';
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
