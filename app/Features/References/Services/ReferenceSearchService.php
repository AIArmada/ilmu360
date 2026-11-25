<?php

declare(strict_types=1);

namespace App\Features\References\Services;

use AIArmada\References\Enums\ReferenceContributorRole;
use App\Features\Persons\Api\PersonLookup;
use App\Features\References\Api\ReferenceSearchCache;
use App\Features\References\Models\Reference;
use App\Features\References\Support\ReferenceScopedSearch;
use App\Platform\Search\DatabaseFuzzyMatcher;
use App\Platform\Search\ScoutRawHitExtractor;
use App\Shared\Contracts\SearchEngineResolver;
use App\Shared\Discovery\PublicDiscoveryAdapter;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @implements ReferenceScopedSearch<Reference>
 */
class ReferenceSearchService implements \App\Features\References\Api\ReferenceSearch, PublicDiscoveryAdapter, ReferenceScopedSearch
{
    private const string PUBLIC_TYPESENSE_FILTER = 'status:=[verified,pending] && published_at:>0';

    public function __construct(
        private readonly ReferenceSearchCache $referenceSearchCache,
        private readonly PersonLookup $personLookup,
        private readonly SearchEngineResolver $engineResolver,
        private readonly ScoutRawHitExtractor $hitExtractor,
        private readonly DatabaseFuzzyMatcher $fuzzyMatcher,
    ) {}

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

        if ($this->engineResolver->isRemoteEngineAvailable()) {
            try {
                return $this->applyScoutSearch($query, $normalizedSearch);
            } catch (Throwable $exception) {
                $this->logScoutFallback('Reference Scout search failed, falling back to database search', $exception, $normalizedSearch);
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

        return $this->scopedFuzzySearchIds($query, $normalizedSearch);
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

        return $this->referenceSearchCache->rememberPublicSearchIds($normalizedSearch, function () use ($normalizedSearch): array {
            if ($this->engineResolver->isRemoteEngineAvailable()) {
                try {
                    $options = $this->engineResolver->usesTypesense()
                        ? [
                            'filter_by' => self::PUBLIC_TYPESENSE_FILTER,
                            'num_typos' => 0,
                        ]
                        : [];

                    return $this->searchIdsWithScout($normalizedSearch, $options);
                } catch (Throwable $exception) {
                    $this->logScoutFallback('Reference Scout public search failed, falling back to database search', $exception, $normalizedSearch);
                }
            }

            return $this->publicSearchIdsFromDatabase($normalizedSearch);
        });
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

        return $this->referenceSearchCache->rememberPublicFuzzySearchIds($normalizedSearch, function () use ($normalizedSearch): array {
            if ($this->engineResolver->isRemoteEngineAvailable()) {
                try {
                    $options = $this->engineResolver->usesTypesense()
                        ? [
                            'filter_by' => self::PUBLIC_TYPESENSE_FILTER,
                            'prioritize_exact_match' => true,
                        ]
                        : [];

                    return $this->searchIdsWithScout($normalizedSearch, $options);
                } catch (Throwable $exception) {
                    $this->logScoutFallback('Reference Scout fuzzy search failed, falling back to database fuzzy search', $exception, $normalizedSearch);
                }
            }

            return $this->publicFuzzySearchIdsFromDatabase($normalizedSearch);
        });
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
    private function publicFuzzySearchIdsFromDatabase(string $normalizedSearch): array
    {
        $rows = Reference::query()
            ->active()
            ->select(['id', 'title', 'parent_id', 'publisher', 'edition_label', 'edition_number', 'isbn', 'language', 'year'])
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateFilter($query, $normalizedSearch))
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateOrdering($query, $normalizedSearch))
            ->limit($this->engineResolver->resultLimit())
            ->get();

        return $this->scoreFuzzyCandidates($rows, $normalizedSearch);
    }

    /**
     * @param  EloquentCollection<int, Reference>  $rows
     * @return list<string>
     */
    private function scoreFuzzyCandidates(EloquentCollection $rows, string $normalizedSearch): array
    {
        $authorNames = $this->inheritedAuthorNamesByReferenceId($rows);
        $minimumScore = $this->fuzzyMatcher->minimumScore($normalizedSearch);
        $maximumDistance = $this->fuzzyMatcher->maximumDistance($normalizedSearch);

        return $rows
            ->map(function (Reference $reference) use ($normalizedSearch, $authorNames, $maximumDistance): array {
                $candidates = [
                    (string) $reference->title,
                    $authorNames[(string) $reference->getKey()] ?? '',
                    (string) ($reference->publisher ?? ''),
                    (string) ($reference->edition_label ?? ''),
                    (string) ($reference->edition_number ?? ''),
                    (string) ($reference->isbn ?? ''),
                    (string) ($reference->language ?? ''),
                    (string) ($reference->year ?? ''),
                ];

                return [
                    'id' => (string) $reference->id,
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
     * @param  Builder<Reference>  $query
     * @return list<string>
     */
    private function scopedFuzzySearchIds(Builder $query, string $normalizedSearch): array
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
            ->limit($this->engineResolver->resultLimit())
            ->get();

        return $this->scoreFuzzyCandidates($rows, $normalizedSearch);
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
            ->where('reference_author_link.contributor_type', $this->personLookup->personMorphType())
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
        $columns = [
            'references.title',
            'references.publisher',
            'references.edition_label',
            'references.isbn',
            'references.language',
        ];
        $patterns = $this->fuzzyMatcher->candidatePatterns($normalizedSearch);

        if ($this->fuzzyMatcher->isPostgres($query)) {
            $threshold = $this->fuzzyMatcher->pgTrgmThreshold();

            return $query->where(function (Builder $candidateQuery) use ($columns, $normalizedSearch, $threshold, $patterns): void {
                foreach ($columns as $column) {
                    $this->fuzzyMatcher->orWherePgTrgm($candidateQuery, $column, $normalizedSearch, $threshold);
                }

                foreach ($patterns as $pattern) {
                    $candidateQuery
                        ->orWhereAuthorNameLike($pattern)
                        ->orWhereLike($this->numericSearchColumn($candidateQuery, 'edition_number'), $pattern);
                }
            });
        }

        if ($patterns === []) {
            return $query;
        }

        return $query->where(function (Builder $candidateQuery) use ($columns, $patterns): void {
            foreach ($patterns as $pattern) {
                foreach ($columns as $column) {
                    $candidateQuery->orWhereLike($column, $pattern);
                }

                $candidateQuery
                    ->orWhereAuthorNameLike($pattern)
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

        return new Expression('CAST('.$wrapped.' AS TEXT)');
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
        $limit = $this->engineResolver->resultLimit();

        if ($this->engineResolver->activeDriver() === 'database') {
            return Reference::search($search)
                ->query(static fn (Builder $query): Builder => $query->limit($limit))
                ->get()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $scoutOptions = $this->engineResolver->usesTypesense()
            ? [
                'query_by' => 'title,authors,search_text',
                'per_page' => $limit,
                ...$options,
            ]
            : ['per_page' => $limit, ...$options];

        $rawResults = Reference::search($search)
            ->options($scoutOptions)
            ->raw();

        return $this->hitExtractor->extractIds($rawResults);
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    protected function applyScoutSearch(Builder $query, string $search): Builder
    {
        $ids = $this->searchIdsWithScout(
            $search,
            $this->engineResolver->usesTypesense() ? ['num_typos' => 0] : [],
        );

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->getModel()->qualifyColumn('id'), $ids);
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
        $this->referenceSearchCache->bumpPublicSearchVersion();
    }

    public function normalizedSearch(string $search): ?string
    {
        return $this->fuzzyMatcher->normalize($search);
    }
}
