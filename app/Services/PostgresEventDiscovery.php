<?php

declare(strict_types=1);

namespace App\Services;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Events\Models\EventLanguage;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventDiscoveryAdapter;
use App\Data\EventDiscoveryCriteria;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Builders\EventBuilder;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Venue;
use App\Support\EventDiscovery\EventDiscoveryFilterSet;
use App\Support\EventDiscovery\FuzzyEventMatcher;
use App\Support\Events\PrimaryOccurrenceSql;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\PersonSearchService;
use App\Support\Search\ReferenceSearchService;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class PostgresEventDiscovery implements EventDiscoveryAdapter
{
    public function __construct(
        private FuzzyEventMatcher $fuzzyMatcher,
        private EventDiscoveryFilterSet $filterSet,
        private PersonSearchService $personSearch,
        private InstitutionSearchService $institutionSearch,
        private ReferenceSearchService $referenceSearch,
        private EventCategoryCatalog $categoryCatalog,
    ) {}

    /** @return LengthAwarePaginator<int, Event> */
    public function search(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        $query = $this->normalizeSearchQuery($criteria->text);
        $filters = $criteria->filters;
        $perPage = $criteria->perPage;
        $sort = $criteria->sort;

        if ($query === null) {
            $queryBuilder = $this->buildDatabaseQuery(null, $filters)
                ->with($this->cardRelationships());

            $this->applyDatabaseOrdering($queryBuilder, $sort, null);

            return $queryBuilder->paginate($perPage);
        }

        $directQuery = $this->buildDatabaseQuery(null, $filters)
            ->with($this->cardRelationships());
        $this->applyDirectSearch(
            $directQuery,
            $query,
            (bool) ($filters['search_include_institutions'] ?? true),
            (bool) ($filters['search_include_speakers'] ?? true),
            (bool) ($filters['search_include_references'] ?? true),
        );
        $this->applyDatabaseOrdering($directQuery, $sort, $query);

        $directMatches = $directQuery->paginate($perPage);

        if ($directMatches->total() > 0 || mb_strlen($query) < 3) {
            return $directMatches;
        }

        return $this->fuzzySearchWithDatabase($filters, $query, $perPage);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearby(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchNearbyWithDatabase(
            $criteria->latitude ?? 0.0,
            $criteria->longitude ?? 0.0,
            (int) ($criteria->radiusKm ?? 0.0),
            $criteria->filters,
            $criteria->perPage,
        );
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchNearbyWithDatabaseQuery(
            $criteria->text ?? '',
            $criteria->latitude ?? 0.0,
            $criteria->longitude ?? 0.0,
            (int) ($criteria->radiusKm ?? 0.0),
            $criteria->filters,
            $criteria->perPage,
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function cardRelationships(): array
    {
        return [
            'media' => fn ($query) => $query
                ->whereIn('collection_name', ['cover', 'poster'])
                ->ordered(),
            'references',
            'classifications',
            'speakers.media' => fn ($query) => $query
                ->where('collection_name', 'avatar')
                ->ordered(),
            'institution.media' => fn ($query) => $query
                ->where('collection_name', 'logo')
                ->ordered(),
            'institution.addresses.country',
            'venue.addresses.country',
            'latestPublishedChangeAnnouncement',
            'primaryOccurrence',
            'timeExpressions',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function buildDatabaseQuery(?string $query, array $filters): EventBuilder
    {
        $timeScope = $this->normalizeTimeScope($filters['time_scope'] ?? null);

        $queryBuilder = Event::query();
        $table = $queryBuilder->getModel()->getTable();

        $queryBuilder
            ->whereIn("{$table}.status", Event::PUBLIC_STATUSES)
            ->where("{$table}.visibility", EventVisibility::Public->value)
            ->whereNotNull("{$table}.published_at");

        $startsAfter = $this->startsAfterDateTime($filters, $timeScope);

        if ($startsAfter instanceof CarbonInterface) {
            $queryBuilder->where(function (Builder $heldAfterQuery) use ($startsAfter, $table): void {
                $heldAfterQuery
                    ->where("{$table}.ends_at", '>=', $startsAfter)
                    ->orWhere(function (Builder $openEndedQuery) use ($startsAfter, $table): void {
                        $openEndedQuery
                            ->whereNull("{$table}.ends_at")
                            ->where("{$table}.starts_at", '>=', $startsAfter);
                    });
            });
        }

        $startsBefore = $this->startsBeforeDateTime($filters, $timeScope);

        if ($startsBefore instanceof CarbonInterface) {
            $queryBuilder->where("{$table}.starts_at", '<=', $startsBefore);
        }

        $startsOnLocalDateRange = $this->startsOnLocalDateRange($filters);

        if ($startsOnLocalDateRange !== null) {
            [$startsOnLocalDateStart, $startsOnLocalDateEnd] = $startsOnLocalDateRange;

            $queryBuilder->whereBetween("{$table}.starts_at", [$startsOnLocalDateStart, $startsOnLocalDateEnd]);
        }

        if (filled($query)) {
            $this->applyDirectSearch(
                $queryBuilder,
                $query,
                (bool) ($filters['search_include_institutions'] ?? true),
                (bool) ($filters['search_include_speakers'] ?? true),
                (bool) ($filters['search_include_references'] ?? true),
            );
        }

        $this->applyLocationAddressFilter($queryBuilder, $this->filterSet->location($filters));

        $languageCodes = $this->normalizeArrayFilter($filters['language_codes'] ?? null);

        if ($languageCodes !== []) {
            $eventTable = $queryBuilder->getModel()->getTable();
            $languageTable = (new EventLanguage)->getTable();

            $queryBuilder->whereExists(function ($languageQuery) use ($eventTable, $languageCodes, $languageTable): void {
                $languageQuery
                    ->selectRaw('1')
                    ->from("{$languageTable} as event_language_filters")
                    ->whereColumn('event_language_filters.event_id', "{$eventTable}.id")
                    ->whereIn('event_language_filters.language_code', $languageCodes);
            });
        }

        $categoryIds = $this->categoryCatalog->descendantIds($this->normalizeArrayFilter($filters['event_category_ids'] ?? null));

        if ($categoryIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($categoryIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $categoryIds)
                    ->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE);
            });
        }

        $eventFormats = $this->normalizeArrayFilter($filters['event_format'] ?? null);

        if ($eventFormats !== []) {
            $queryBuilder->whereIn('delivery_mode', $eventFormats);
        }

        if (! empty($filters['gender'])) {
            $queryBuilder->where('gender', $filters['gender']);
        }

        $ageGroups = $this->normalizeArrayFilter($filters['age_group'] ?? null);

        if ($ageGroups !== []) {
            $queryBuilder->whereHas('audiences', function (Builder $ageGroupQuery) use ($ageGroups): void {
                $ageGroupQuery
                    ->where('audience_type', 'age_group')
                    ->whereIn('value', $ageGroups);
            });
        }

        $childrenAllowed = $this->normalizeBooleanFilter($filters['children_allowed'] ?? null);

        if ($childrenAllowed !== null) {
            $queryBuilder->whereHas('audienceProfiles', fn (Builder $q) => $q->where('is_child_friendly', $childrenAllowed));
        }

        $isMuslimOnly = $this->normalizeBooleanFilter($filters['is_muslim_only'] ?? null);

        if ($isMuslimOnly !== null) {
            if ($isMuslimOnly) {
                $queryBuilder->whereHas('audiences', fn (Builder $q) => $q->where('audience_type', 'religion')->where('value', 'muslim_only'));
            } else {
                $queryBuilder->whereDoesntHave('audiences', fn (Builder $q) => $q->where('audience_type', 'religion'));
            }
        }

        if (! empty($filters['institution_id'])) {
            $queryBuilder->where('institution_id', $filters['institution_id']);
        }

        if (! empty($filters['venue_id'])) {
            $queryBuilder->where('default_venue_id', $filters['venue_id']);
        }

        $personIds = $this->uuidFilterValues($filters['speaker_ids'] ?? null);

        if ($personIds !== []) {
            $queryBuilder->whereHas('persons', function (Builder $personQuery) use ($personIds) {
                $personQuery->whereIn('persons.id', $personIds);
            });
        }

        $keyPersonRoles = $this->normalizeKeyPersonRoles($filters['key_person_roles'] ?? null);

        if ($keyPersonRoles !== []) {
            $queryBuilder->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($keyPersonRoles): void {
                $keyPersonQuery->whereIn('role_code', $keyPersonRoles);
            });
        }

        foreach ([
            'person_in_charge_ids' => EventKeyPersonRole::PersonInCharge,
            'moderator_ids' => EventKeyPersonRole::Moderator,
            'imam_ids' => EventKeyPersonRole::Imam,
            'khatib_ids' => EventKeyPersonRole::Khatib,
            'bilal_ids' => EventKeyPersonRole::Bilal,
        ] as $filterKey => $role) {
            $roleSpecificIds = $this->uuidFilterValues($filters[$filterKey] ?? null);

            if ($roleSpecificIds === []) {
                continue;
            }

            $queryBuilder->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($roleSpecificIds, $role): void {
                $keyPersonQuery
                    ->where('role_code', $role->value)
                    ->where('involveable_type', 'speaker')
                    ->whereIn('involveable_id', $roleSpecificIds);
            });
        }

        $personInChargeSearch = $this->normalizeTextFilter($filters['person_in_charge_search'] ?? null);

        if ($personInChargeSearch !== null) {
            $operator = $this->databaseLikeOperator();

            $queryBuilder->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($operator, $personInChargeSearch): void {
                $keyPersonQuery
                    ->where('role_code', EventKeyPersonRole::PersonInCharge->value)
                    ->where(function (Builder $personInChargeQuery) use ($operator, $personInChargeSearch): void {
                        $personInChargeQuery
                            ->where('event_involvements.display_name', $operator, "%{$personInChargeSearch}%")
                            ->orWhereHas('speaker', function (Builder $speakerQuery) use ($operator, $personInChargeSearch): void {
                                $speakerQuery
                                    ->where('speakers.name', $operator, "%{$personInChargeSearch}%")
                                    ->orWhere('speakers.searchable_name', $operator, "%{$personInChargeSearch}%");
                            });
                    });
            });
        }

        $topicIds = $this->normalizeArrayFilter($filters['topic_ids'] ?? null);

        if ($topicIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($topicIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $topicIds)
                    ->whereIn('taxonomy_code', ['discipline', 'issue']);
            });
        }

        $domainTagIds = $this->normalizeArrayFilter($filters['domain_tag_ids'] ?? null);

        if ($domainTagIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($domainTagIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $domainTagIds)
                    ->where('taxonomy_code', 'domain');
            });
        }

        $sourceTagIds = $this->normalizeArrayFilter($filters['source_tag_ids'] ?? null);

        if ($sourceTagIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($sourceTagIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $sourceTagIds)
                    ->where('taxonomy_code', 'source');
            });
        }

        $issueTagIds = $this->normalizeArrayFilter($filters['issue_tag_ids'] ?? null);

        if ($issueTagIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($issueTagIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $issueTagIds)
                    ->where('taxonomy_code', 'issue');
            });
        }

        $referenceFilter = $this->resolvedReferenceFilter($filters);

        if ($referenceFilter['ids'] !== []) {
            $queryBuilder->whereHas('references', function (Builder $referenceQuery) use ($referenceFilter): void {
                $referenceQuery->whereIn('references.id', $referenceFilter['ids']);
            });
        } elseif ($referenceFilter['has_author_filter']) {
            $queryBuilder->whereRaw('1 = 0');
        }

        $timingMode = $this->normalizeTimingModeFilter($filters['timing_mode'] ?? null);
        $prayerTime = $this->normalizePrayerTimeFilter($filters['prayer_time'] ?? null);

        if ($prayerTime !== null && $timingMode !== TimingMode::Absolute->value) {
            $queryBuilder
                ->whereHas('timeExpressions', function (Builder $prayerQuery) use ($prayerTime): void {
                    $prayerQuery->where('time_mode', TimingMode::PrayerRelative->value);
                    $prayerQuery->where('anchor_type', 'prayer');

                    $prayerQuery->where(function (Builder $inner) use ($prayerTime): void {
                        $inner->where('display_label', $this->databaseLikeOperator(), "%{$prayerTime}%");

                        if (($prayerReference = $this->resolvePrayerReferenceFromFilter($prayerTime)) instanceof PrayerReference) {
                            $inner->orWhere('anchor_code', $prayerReference->value);
                        }
                    });
                });
        }

        if ($timingMode !== null) {
            if ($timingMode === TimingMode::PrayerRelative->value) {
                $queryBuilder->whereHas('timeExpressions', fn (Builder $timeQuery) => $timeQuery->where('time_mode', TimingMode::PrayerRelative->value));
            } else {
                $queryBuilder->whereDoesntHave('timeExpressions', fn (Builder $timeQuery) => $timeQuery->where('time_mode', TimingMode::PrayerRelative->value));
            }
        }

        $startsTimeFrom = $this->normalizeTimeFilter($filters['starts_time_from'] ?? null);
        $startsTimeUntil = $this->normalizeTimeFilter($filters['starts_time_until'] ?? null);

        if (
            $timingMode === TimingMode::Absolute->value
            && ($startsTimeFrom !== null || $startsTimeUntil !== null)
        ) {
            $this->applyAbsoluteTimeRangeFilter($queryBuilder, $startsTimeFrom, $startsTimeUntil);
        }

        $hasEventUrl = $this->normalizeBooleanFilter($filters['has_event_url'] ?? null);

        if ($hasEventUrl === true) {
            $queryBuilder->whereHas('links', fn (Builder $q) => $q->where('link_type', 'external')->where('url', '!=', ''));
        } elseif ($hasEventUrl === false) {
            $queryBuilder->whereDoesntHave('links', fn (Builder $q) => $q->where('link_type', 'external')->where('url', '!=', ''));
        }

        $hasLiveUrl = $this->normalizeBooleanFilter($filters['has_live_url'] ?? null);

        if ($hasLiveUrl === true) {
            $queryBuilder->whereHas('links', fn (Builder $q) => $q->where('link_type', 'streaming')->where('url', '!=', ''));
        } elseif ($hasLiveUrl === false) {
            $queryBuilder->whereDoesntHave('links', fn (Builder $q) => $q->where('link_type', 'streaming')->where('url', '!=', ''));
        }

        $hasEndTime = $this->normalizeBooleanFilter($filters['has_end_time'] ?? null);

        if ($hasEndTime === true) {
            $queryBuilder->whereNotNull('ends_at');
        } elseif ($hasEndTime === false) {
            $queryBuilder->whereNull('ends_at');
        }

        return $queryBuilder;
    }

    protected function applyDirectSearch(
        EventBuilder $queryBuilder,
        string $search,
        bool $includeInstitutions = true,
        bool $includeSpeakers = true,
        bool $includeReferences = true,
    ): void {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return;
        }

        $operator = strtolower($this->databaseLikeOperator());
        $collapsedSearch = preg_replace('/\s+/u', ' ', $normalizedSearch) ?? '';
        $collapsedWildcardSearch = '%'.str_replace(' ', '%', $collapsedSearch).'%';

        /** @var list<string> $searchTokens */
        $searchTokens = array_values(array_filter(
            explode(' ', $collapsedSearch),
            static fn (string $token): bool => $token !== ''
        ));

        $speakerIds = $includeSpeakers ? $this->personSearch->publicSearchIds($normalizedSearch) : [];
        $institutionIds = $includeInstitutions ? $this->institutionSearch->publicSearchIds($normalizedSearch) : [];
        $referenceIds = $includeReferences ? $this->referenceSearch->publicSearchIds($normalizedSearch) : [];

        $queryBuilder->where(function (Builder $nestedQuery) use ($normalizedSearch, $operator, $collapsedWildcardSearch, $searchTokens, $speakerIds, $institutionIds, $referenceIds, $includeSpeakers): void {
            $nestedQuery->where(function (Builder $titleQuery) use ($normalizedSearch, $operator, $collapsedWildcardSearch, $searchTokens): void {
                $titleQuery
                    ->where('title', $operator, "%{$normalizedSearch}%")
                    ->orWhere('title', $operator, $collapsedWildcardSearch);

                foreach ($searchTokens as $token) {
                    if (mb_strlen($token) < 3) {
                        continue;
                    }

                    $titleQuery->orWhere('title', $operator, "%{$token}%");
                }
            });

            if ($institutionIds !== []) {
                $institutionIdExpression = 'events.institution_id';

                $nestedQuery->orWhere(function (Builder $institutionQuery) use ($institutionIdExpression, $institutionIds): void {
                    foreach ($institutionIds as $index => $institutionId) {
                        if ($index === 0) {
                            $institutionQuery->whereRaw("{$institutionIdExpression} = ?", [$institutionId]);

                            continue;
                        }

                        $institutionQuery->orWhereRaw("{$institutionIdExpression} = ?", [$institutionId]);
                    }
                });
            }

            if ($includeSpeakers) {
                $nestedQuery->orWhereHas('keyPeople', function (Builder $keyPeopleQuery) use ($speakerIds, $normalizedSearch, $operator): void {
                    $keyPeopleQuery->where(function (Builder $inner) use ($speakerIds, $normalizedSearch, $operator): void {
                        $inner
                            ->where('event_involvements.display_name', $operator, "%{$normalizedSearch}%")
                            ->orWhereHas('speaker', fn (Builder $speakerQuery) => $speakerQuery
                                ->where('name', $operator, "%{$normalizedSearch}%")
                                ->orWhere('searchable_name', $operator, "%{$normalizedSearch}%")
                            );

                        if ($speakerIds !== []) {
                            $inner->orWhereIn('event_involvements.involveable_id', $speakerIds);
                        }
                    });
                });
            }

            if ($referenceIds !== []) {
                $nestedQuery->orWhereHas('references', function (Builder $referenceQuery) use ($referenceIds): void {
                    $referenceQuery->whereIn('references.id', $referenceIds);
                });
            }
        });
    }

    /**
     * @param  Builder<Event>  $queryBuilder
     */
    protected function applyDatabaseOrdering(Builder $queryBuilder, string $sort, ?string $query): void
    {
        if ($sort === 'relevance' && is_string($query) && $query !== '') {
            $operator = $this->databaseLikeOperator();

            $queryBuilder
                ->orderByRaw(
                    "CASE
                        WHEN events.title {$operator} ? THEN 1
                        ELSE 2
                    END",
                    ["%{$query}%"]
                )
                ->orderBy('starts_at');

            return;
        }

        $queryBuilder->orderBy('starts_at', 'asc');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function fuzzySearchWithDatabase(array $filters, string $search, int $perPage): LengthAwarePaginator
    {
        $normalizedSearch = $this->fuzzyMatcher->normalizeForSimilarity($search);

        if ($normalizedSearch === '') {
            $queryBuilder = $this->buildDatabaseQuery(null, $filters)
                ->with($this->cardRelationships())
                ->orderBy('starts_at', 'asc');

            return $queryBuilder->paginate($perPage);
        }

        $candidateQuery = $this->buildDatabaseQuery(null, $filters)
            ->select(['events.id', 'events.title'])
            ->tap(fn (Builder $query): Builder => $this->fuzzyMatcher->applyFuzzyTitleCandidateFilter($query, $normalizedSearch))
            ->tap(fn (Builder $query): Builder => $this->fuzzyMatcher->applyFuzzyTitleCandidateOrdering($query, $normalizedSearch));

        $rankedCandidates = $candidateQuery
            ->get()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'score' => $this->fuzzyMatcher->eventSimilarityScore($normalizedSearch, $event),
            ])
            ->filter(static fn (array $candidate): bool => $candidate['score'] >= 0.70)
            ->sortByDesc('score')
            ->values();

        $currentPage = max(1, (int) Paginator::resolveCurrentPage());
        $paginationMeta = [
            'path' => request()->url(),
            'query' => request()->query(),
        ];

        if ($rankedCandidates->isEmpty()) {
            return new Paginator(collect(), 0, $perPage, $currentPage, $paginationMeta);
        }

        /** @var list<string> $orderedIds */
        $orderedIds = $rankedCandidates->pluck('id')->all();
        $paginatedIds = array_slice($orderedIds, ($currentPage - 1) * $perPage, $perPage);

        if ($paginatedIds === []) {
            return new Paginator(collect(), count($orderedIds), $perPage, $currentPage, $paginationMeta);
        }

        $events = $this->buildDatabaseQuery(null, $filters)
            ->with($this->cardRelationships())
            ->whereIn('events.id', $paginatedIds)
            ->get()
            ->sortBy(static function (Event $event) use ($paginatedIds): int {
                $position = array_search($event->id, $paginatedIds, true);

                return is_int($position) ? $position : PHP_INT_MAX;
            })
            ->values();

        return new Paginator($events, count($orderedIds), $perPage, $currentPage, $paginationMeta);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchNearbyWithDatabase(
        float $lat,
        float $lng,
        int $radiusKm,
        array $filters,
        int $perPage
    ): LengthAwarePaginator {
        $addressablesTable = config('addressing.tables.addressables', 'addressables');
        $addressesTable = config('addressing.tables.addresses', 'addresses');
        $institutionIdExpression = 'events.institution_id';
        $latitudeExpression = 'coalesce(venue_addresses.latitude, institution_addresses.latitude)';
        $longitudeExpression = 'coalesce(venue_addresses.longitude, institution_addresses.longitude)';
        $distanceSql = "(6371 * acos(cos(radians(?)) * cos(radians({$latitudeExpression})) * cos(radians({$longitudeExpression}) - radians(?)) + sin(radians(?)) * sin(radians({$latitudeExpression}))))";
        $venueMorphType = (new Venue)->getMorphClass();
        $institutionMorphType = (new Institution)->getMorphClass();

        $queryBuilder = $this->buildDatabaseQuery(null, $filters)
            ->leftJoin("{$addressablesTable} as venue_addressables", function ($join) use ($venueMorphType) {
                $join->on('venue_addressables.addressable_id', '=', 'events.default_venue_id')
                    ->where('venue_addressables.addressable_type', $venueMorphType)
                    ->where('venue_addressables.is_primary', true);
            })
            ->leftJoin("{$addressesTable} as venue_addresses", 'venue_addresses.id', '=', 'venue_addressables.address_id')
            ->leftJoin("{$addressablesTable} as institution_addressables", function ($join) use ($institutionIdExpression, $institutionMorphType) {
                $join->whereRaw("institution_addressables.addressable_id = {$institutionIdExpression}")
                    ->where('institution_addressables.addressable_type', $institutionMorphType)
                    ->where('institution_addressables.is_primary', true);
            })
            ->leftJoin("{$addressesTable} as institution_addresses", 'institution_addresses.id', '=', 'institution_addressables.address_id')
            ->whereRaw("{$latitudeExpression} is not null")
            ->whereRaw("{$longitudeExpression} is not null")
            ->select('events.*')
            ->selectRaw("{$distanceSql} as distance_km", [$lat, $lng, $lat])
            ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radiusKm])
            ->with($this->cardRelationships())
            ->orderBy('distance_km', 'asc')
            ->orderBy('starts_at', 'asc');

        return $queryBuilder->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchNearbyWithDatabaseQuery(
        string $query,
        float $lat,
        float $lng,
        int $radiusKm,
        array $filters,
        int $perPage
    ): LengthAwarePaginator {
        $addressablesTable = config('addressing.tables.addressables', 'addressables');
        $addressesTable = config('addressing.tables.addresses', 'addresses');
        $institutionIdExpression = 'events.institution_id';
        $latitudeExpression = 'coalesce(venue_addresses.latitude, institution_addresses.latitude)';
        $longitudeExpression = 'coalesce(venue_addresses.longitude, institution_addresses.longitude)';
        $distanceSql = "(6371 * acos(cos(radians(?)) * cos(radians({$latitudeExpression})) * cos(radians({$longitudeExpression}) - radians(?)) + sin(radians(?)) * sin(radians({$latitudeExpression}))))";
        $venueMorphType = (new Venue)->getMorphClass();
        $institutionMorphType = (new Institution)->getMorphClass();

        $queryBuilder = $this->buildDatabaseQuery(null, $filters);
        $this->applyDirectSearch($queryBuilder, $query,
            (bool) ($filters['search_include_institutions'] ?? true),
            (bool) ($filters['search_include_speakers'] ?? true),
            (bool) ($filters['search_include_references'] ?? true),
        );

        $queryBuilder
            ->leftJoin("{$addressablesTable} as venue_addressables", function ($join) use ($venueMorphType) {
                $join->on('venue_addressables.addressable_id', '=', 'events.default_venue_id')
                    ->where('venue_addressables.addressable_type', $venueMorphType)
                    ->where('venue_addressables.is_primary', true);
            })
            ->leftJoin("{$addressesTable} as venue_addresses", 'venue_addresses.id', '=', 'venue_addressables.address_id')
            ->leftJoin("{$addressablesTable} as institution_addressables", function ($join) use ($institutionIdExpression, $institutionMorphType) {
                $join->whereRaw("institution_addressables.addressable_id = {$institutionIdExpression}")
                    ->where('institution_addressables.addressable_type', $institutionMorphType)
                    ->where('institution_addressables.is_primary', true);
            })
            ->leftJoin("{$addressesTable} as institution_addresses", 'institution_addresses.id', '=', 'institution_addressables.address_id')
            ->whereRaw("{$latitudeExpression} is not null")
            ->whereRaw("{$longitudeExpression} is not null")
            ->select('events.*')
            ->selectRaw("{$distanceSql} as distance_km", [$lat, $lng, $lat])
            ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radiusKm])
            ->with($this->cardRelationships())
            ->orderBy('distance_km', 'asc')
            ->orderBy('starts_at', 'asc');

        return $queryBuilder->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function startsAfterDateTime(array $filters, string $timeScope = 'upcoming'): ?CarbonInterface
    {
        $startsAfter = $this->parseDateFilter($filters['starts_after'] ?? null, false);

        if ($startsAfter instanceof CarbonInterface) {
            if ($timeScope === 'upcoming' && now()->greaterThan($startsAfter)) {
                return now();
            }

            return $startsAfter;
        }

        if ($timeScope === 'upcoming') {
            return now();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function startsBeforeDateTime(array $filters, string $timeScope = 'upcoming'): ?CarbonInterface
    {
        $startsBefore = $this->parseDateFilter($filters['starts_before'] ?? null, true);

        if ($startsBefore instanceof CarbonInterface) {
            return $startsBefore;
        }

        if ($timeScope === 'past') {
            return now();
        }

        return null;
    }

    protected function applyLocationAddressFilter(EventBuilder $queryBuilder, AddressLocationData $location): void
    {
        foreach ($location->criteria() as $column => $value) {
            $this->applyLocationAddressCriterion($queryBuilder, $column, $value);
        }
    }

    protected function applyLocationAddressCriterion(EventBuilder $queryBuilder, string $column, string $value): void
    {
        $addressColumn = $column;
        $addressesTable = config('addressing.tables.addresses', 'addresses');
        $addressablesTable = config('addressing.tables.addressables', 'addressables');
        $institutionIdExpression = 'events.institution_id';
        $venueMorphType = (new Venue)->getMorphClass();
        $institutionMorphType = (new Institution)->getMorphClass();

        $queryBuilder->where(function (Builder $locationQuery) use (
            $addressColumn,
            $value,
            $addressesTable,
            $addressablesTable,
            $institutionIdExpression,
            $venueMorphType,
            $institutionMorphType,
        ): void {
            $locationQuery
                ->whereExists(function ($addressQuery) use ($addressColumn, $value, $addressesTable, $addressablesTable, $venueMorphType): void {
                    $addressQuery
                        ->select(DB::raw(1))
                        ->from($addressesTable)
                        ->join($addressablesTable, "{$addressesTable}.id", '=', "{$addressablesTable}.address_id")
                        ->whereColumn("{$addressablesTable}.addressable_id", 'events.default_venue_id')
                        ->where("{$addressablesTable}.addressable_type", $venueMorphType)
                        ->where("{$addressablesTable}.is_primary", true)
                        ->where("{$addressesTable}.{$addressColumn}", $value);
                })
                ->orWhereExists(function ($addressQuery) use ($addressColumn, $value, $addressesTable, $addressablesTable, $institutionIdExpression, $institutionMorphType): void {
                    $addressQuery
                        ->select(DB::raw(1))
                        ->from($addressesTable)
                        ->join($addressablesTable, "{$addressesTable}.id", '=', "{$addressablesTable}.address_id")
                        ->whereRaw("{$addressablesTable}.addressable_id = {$institutionIdExpression}")
                        ->where("{$addressablesTable}.addressable_type", $institutionMorphType)
                        ->where("{$addressablesTable}.is_primary", true)
                        ->where("{$addressesTable}.{$addressColumn}", $value);
                });
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function startsAfterTimestamp(array $filters, string $timeScope = 'upcoming'): ?int
    {
        return $this->startsAfterDateTime($filters, $timeScope)?->timestamp;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function startsBeforeTimestamp(array $filters, string $timeScope = 'upcoming'): ?int
    {
        $startsBefore = $this->startsBeforeDateTime($filters, $timeScope);

        return $startsBefore?->timestamp;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonInterface, 1: CarbonInterface}|null
     */
    protected function startsOnLocalDateRange(array $filters): ?array
    {
        $startsOnLocalDateStart = $this->parseDateFilter($filters['starts_on_local_date'] ?? null, false);
        $startsOnLocalDateEnd = $this->parseDateFilter($filters['starts_on_local_date'] ?? null, true);

        if ($startsOnLocalDateStart instanceof CarbonInterface && $startsOnLocalDateEnd instanceof CarbonInterface) {
            return [$startsOnLocalDateStart, $startsOnLocalDateEnd];
        }

        return null;
    }

    protected function normalizeTimeScope(mixed $value): string
    {
        if (! is_string($value)) {
            return 'upcoming';
        }

        return in_array($value, ['upcoming', 'past', 'all'], true) ? $value : 'upcoming';
    }

    protected function parseDateFilter(mixed $value, bool $endOfDay): ?CarbonInterface
    {
        return UserDateTimeFormatter::parseUserDateToUtc($value, $endOfDay);
    }

    /**
     * @return array<int, mixed>
     */
    protected function normalizeArrayFilter(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter($values, fn (mixed $item): bool => $item !== null && $item !== ''));
    }

    /**
     * @return list<string>
     */
    private function uuidFilterValues(mixed $value): array
    {
        return array_values(array_filter(
            $this->normalizeArrayFilter($value),
            fn (string $candidate): bool => Str::isUuid($candidate),
        ));
    }

    /**
     * @return list<string>
     */
    protected function expandedReferenceIdsForFiltering(mixed $value): array
    {
        $referenceIds = $this->uuidFilterValues($value);

        return Reference::expandRootReferenceIdsForFiltering($referenceIds);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{ids: list<string>, has_author_filter: bool}
     */
    protected function resolvedReferenceFilter(array $filters): array
    {
        $referenceIds = $this->expandedReferenceIdsForFiltering($filters['reference_ids'] ?? null);
        $hasAuthorFilter = false;

        foreach ($this->normalizeArrayFilter($filters['reference_author_search'] ?? null) as $authorSearch) {
            if (! is_string($authorSearch) && ! is_numeric($authorSearch)) {
                continue;
            }

            $authorSearch = trim((string) $authorSearch);

            if ($authorSearch === '') {
                continue;
            }

            $hasAuthorFilter = true;
            $referenceIds = array_values(array_unique([
                ...$referenceIds,
                ...$this->referenceSearch->publicSearchIds($authorSearch),
            ]));
        }

        return [
            'ids' => $referenceIds,
            'has_author_filter' => $hasAuthorFilter,
        ];
    }

    /**
     * @return list<string>
     */
    protected function normalizeKeyPersonRoles(mixed $value): array
    {
        return collect($this->normalizeArrayFilter($value))
            ->map(fn (mixed $role): ?string => EventKeyPersonRole::tryFrom((string) $role)?->value)
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeBooleanFilter(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return null;
    }

    protected function normalizeTextFilter(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    protected function normalizePrayerTimeFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        $enum = EventPrayerTime::tryFrom($normalized);

        if ($enum instanceof EventPrayerTime) {
            return mb_strtolower($enum->getLabel());
        }

        return $normalized;
    }

    protected function normalizeTimingModeFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, [TimingMode::Absolute->value, TimingMode::PrayerRelative->value], true)
            ? $value
            : null;
    }

    protected function normalizeTimeFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        try {
            return now(UserDateTimeFormatter::resolveTimezone())
                ->setTimeFromTimeString($normalized)
                ->format('H:i');
        } catch (Throwable) {
            return null;
        }
    }

    protected function applyAbsoluteTimeRangeFilter(
        EventBuilder $queryBuilder,
        ?string $startsTimeFrom,
        ?string $startsTimeUntil
    ): void {
        if ($startsTimeFrom === null && $startsTimeUntil === null) {
            return;
        }

        $expression = $this->startsAtUserTimeSqlExpression($this->userUtcOffsetMinutes());

        if ($startsTimeFrom !== null && $startsTimeUntil !== null) {
            if ($startsTimeFrom <= $startsTimeUntil) {
                $queryBuilder
                    ->whereRaw("{$expression} >= ?", [$startsTimeFrom])
                    ->whereRaw("{$expression} <= ?", [$startsTimeUntil]);

                return;
            }

            $queryBuilder->where(function (Builder $timeQuery) use ($expression, $startsTimeFrom, $startsTimeUntil): void {
                $timeQuery
                    ->whereRaw("{$expression} >= ?", [$startsTimeFrom])
                    ->orWhereRaw("{$expression} <= ?", [$startsTimeUntil]);
            });

            return;
        }

        if ($startsTimeFrom !== null) {
            $queryBuilder->whereRaw("{$expression} >= ?", [$startsTimeFrom]);

            return;
        }

        $queryBuilder->whereRaw("{$expression} <= ?", [(string) $startsTimeUntil]);
    }

    protected function resolvePrayerReferenceFromFilter(string $prayerTime): ?PrayerReference
    {
        if (str_contains($prayerTime, 'jumaat') || str_contains($prayerTime, 'friday')) {
            return PrayerReference::FridayPrayer;
        }

        if (str_contains($prayerTime, 'maghrib')) {
            return PrayerReference::Maghrib;
        }

        if (str_contains($prayerTime, 'asar') || str_contains($prayerTime, 'asr')) {
            return PrayerReference::Asr;
        }

        if (str_contains($prayerTime, 'subuh') || str_contains($prayerTime, 'fajr')) {
            return PrayerReference::Fajr;
        }

        if (str_contains($prayerTime, 'zohor') || str_contains($prayerTime, 'zuhur') || str_contains($prayerTime, 'dhuhr')) {
            return PrayerReference::Dhuhr;
        }

        if (str_contains($prayerTime, 'isyak') || str_contains($prayerTime, 'isha')) {
            return PrayerReference::Isha;
        }

        return null;
    }

    protected function databaseDriver(): string
    {
        /** @var Connection $connection */
        $connection = Event::query()->getConnection();

        return $connection->getDriverName();
    }

    private function databaseLikeOperator(): string
    {
        return $this->databaseDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    private function startsAtUserTimeSqlExpression(int $offsetMinutes): string
    {
        return PrimaryOccurrenceSql::startsAtUserTimeExpression($offsetMinutes);
    }

    private function userUtcOffsetMinutes(): int
    {
        return now(UserDateTimeFormatter::resolveTimezone())->utcOffset();
    }

    private function normalizeSearchQuery(?string $query): ?string
    {
        if (! is_string($query)) {
            return null;
        }

        $normalizedQuery = trim($query);

        return $normalizedQuery === '' ? null : $normalizedQuery;
    }
}
