<?php

declare(strict_types=1);

namespace App\Features\Persons\Services;

use App\Features\Persons\Api\PersonSearch;
use App\Features\Persons\Api\PersonSearchCache;
use App\Features\Persons\Api\PersonSearchMaintenance;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Support\PersonSearchText;
use App\Platform\Search\DatabaseFuzzyMatcher;
use App\Platform\Search\ScoutRawHitExtractor;
use App\Shared\Contracts\SearchEngineResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PersonSearchService implements PersonSearch, PersonSearchMaintenance
{
    public function __construct(
        private readonly PersonWorkflow $personWorkflow,
        private readonly PersonSearchCache $personSearchCache,
        private readonly SearchEngineResolver $engineResolver,
        private readonly ScoutRawHitExtractor $hitExtractor,
        private readonly DatabaseFuzzyMatcher $fuzzyMatcher,
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
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public function applyIndexedSearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->engineResolver->isRemoteEngineAvailable()) {
            try {
                return $this->applyScoutSearch($query, $normalizedSearch);
            } catch (Throwable $exception) {
                $this->logScoutFallback('Person Scout search failed, falling back to local search', $exception, $normalizedSearch);
            }
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
        $searchTokens = $this->fuzzyMatcher->tokens($search, 2);

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
     * @return list<string>
     */
    public function publicSearchIds(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        return $this->personSearchCache->rememberPublicSearchIds($normalizedSearch, function () use ($normalizedSearch): array {
            if ($this->engineResolver->isRemoteEngineAvailable() || $this->engineResolver->activeDriver() === 'database') {
                try {
                    $options = $this->engineResolver->usesTypesense() || $this->engineResolver->activeDriver() === 'database'
                        ? [
                            'filter_by' => 'status:=[verified,pending]',
                            'num_typos' => 0,
                        ]
                        : [];

                    $scoutIds = $this->searchIdsWithScout($normalizedSearch, $options);

                    if ($scoutIds !== []) {
                        return $scoutIds;
                    }
                } catch (Throwable $exception) {
                    $this->logScoutFallback('Person Scout public search failed, falling back to local search', $exception, $normalizedSearch);
                }
            }

            return $this->publicSearchIdsFromLocalSearch($normalizedSearch);
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
    private function publicSearchIdsFromLocalSearch(string $normalizedSearch): array
    {
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

        return $this->personSearchCache->rememberPublicFuzzySearchIds($normalizedSearch, function () use ($normalizedSearch): array {
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
                    $this->logScoutFallback('Person Scout fuzzy search failed, falling back to local fuzzy search', $exception, $normalizedSearch);
                }
            }

            $rows = Person::query()
                ->whereIn('status', ['verified', 'pending'])
                ->select(['id', 'name', 'middle_name', 'family_name', 'searchable_name'])
                ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateFilter($query, $normalizedSearch))
                ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateOrdering($query, $normalizedSearch))
                ->limit($this->engineResolver->resultLimit())
                ->get();

            return $this->scoreFuzzyCandidates($rows, $normalizedSearch);
        });
    }

    /**
     * @param  Builder<Person>  $query
     * @return list<string>
     */
    private function scopedFuzzySearchIds(Builder $query, string $normalizedSearch): array
    {
        $model = $query->getModel();
        $keyColumn = $model->qualifyColumn($model->getKeyName());

        $rows = (clone $query)
            ->reorder()
            ->select([
                $keyColumn,
                $model->qualifyColumn('name'),
                $model->qualifyColumn('middle_name'),
                $model->qualifyColumn('family_name'),
                $model->qualifyColumn('searchable_name'),
            ])
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateFilter($builder, $normalizedSearch))
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateOrdering($builder, $normalizedSearch))
            ->limit($this->engineResolver->resultLimit())
            ->get();

        return $this->scoreFuzzyCandidates($rows, $normalizedSearch);
    }

    /**
     * @param  EloquentCollection<int, Person>  $rows
     * @return list<string>
     */
    private function scoreFuzzyCandidates(EloquentCollection $rows, string $normalizedSearch): array
    {
        return $rows
            ->map(function (Person $person) use ($normalizedSearch): array {
                $candidate = $this->personCandidateSearchableName($person);

                if ($candidate === '') {
                    return ['id' => (string) $person->id, 'score' => 0.0];
                }

                return [
                    'id' => (string) $person->id,
                    'score' => $this->fuzzyMatcher->bestScore(
                        $normalizedSearch,
                        [$candidate],
                        enforceShortQuerySubstringOnTokens: true,
                    ),
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
        return $this->fuzzyMatcher->applyColumnsFilter(
            $query,
            ['persons.searchable_name', 'persons.name', 'persons.family_name'],
            $normalizedSearch,
        );
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    private function applyFuzzyCandidateOrdering(Builder $query, string $normalizedSearch): Builder
    {
        $primaryColumn = 'persons.searchable_name';

        return $query
            ->orderByRaw(
                "case when lower(coalesce({$primaryColumn}, '')) = ? then 0 when lower(coalesce({$primaryColumn}, '')) like ? then 1 else 2 end",
                [$normalizedSearch, $normalizedSearch.'%']
            )
            ->orderByRaw("length(coalesce({$primaryColumn}, ''))")
            ->orderBy($primaryColumn)
            ->orderBy('persons.id');
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
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

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    protected function searchIdsWithScout(string $search, array $options = []): array
    {
        $limit = $this->engineResolver->resultLimit();

        if ($this->engineResolver->activeDriver() === 'database') {
            return Person::search($search)
                ->query(static function (Builder $query) use ($options, $limit): Builder {
                    if (($options['filter_by'] ?? null) === 'status:=[verified,pending]') {
                        $query->whereIn('status', ['verified', 'pending']);
                    }

                    return $query->limit($limit);
                })
                ->get()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $scoutOptions = $this->engineResolver->usesTypesense()
            ? [
                'query_by' => 'formatted_name,search_text,name',
                'per_page' => $limit,
                ...$options,
            ]
            : ['per_page' => $limit, ...$options];

        $rawResults = Person::search($search)
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

    public function syncPersonRecord(Person $person): void
    {
        $this->personWorkflow->syncSearchProjection((string) $person->getKey());
    }

    public function reindexAll(int $chunkSize = 100): int
    {
        return $this->personWorkflow->reindexSearch($chunkSize);
    }

    public function purgePersonRecord(Person $person): void
    {
        $this->personWorkflow->purgeSearchProjection((string) $person->getKey());
    }

    public function bustPublicSearchCache(): void
    {
        $this->personSearchCache->bumpPublicSearchVersion();
    }

    public function normalizedSearch(string $search): ?string
    {
        return $this->fuzzyMatcher->normalize($search);
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
}
