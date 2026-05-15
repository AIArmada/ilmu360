<?php

namespace App\Services;

use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Venue;
use App\Support\Cache\SafeModelCache;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\ReferenceSearchService;
use App\Support\Search\SpeakerSearchService;
use App\Support\Search\TypesenseHealthCheckService;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventSearchService
{
    public function __construct(
        private readonly TypesenseHealthCheckService $healthCheck,
        private readonly SpeakerSearchService $speakerSearch,
        private readonly InstitutionSearchService $institutionSearch,
        private readonly ReferenceSearchService $referenceSearch,
    ) {}

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
            'tags',
            'languages',
            'speakers.media' => fn ($query) => $query
                ->where('collection_name', 'avatar')
                ->ordered(),
            'institution.media' => fn ($query) => $query
                ->where('collection_name', 'logo')
                ->ordered(),
            'institution.address.state',
            'institution.address.district',
            'institution.address.subdistrict',
            'venue.address.state',
            'venue.address.district',
            'venue.address.subdistrict',
            'latestPublishedChangeAnnouncement',
        ];
    }

    /**
     * Search events using Typesense if available, otherwise fallback to database.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 20,
        string $sort = 'time'
    ): LengthAwarePaginator {
        if ($this->usesDefaultSearchCache($query, $filters, $perPage, $sort)) {
            return $this->cachedDefaultSearch($perPage);
        }

        return $this->performSearch($query, $filters, $perPage, $sort);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function performSearch(
        ?string $query = null,
        array $filters = [],
        int $perPage = 20,
        string $sort = 'time'
    ): LengthAwarePaginator {
        if ($this->requiresDatabaseFiltering($filters)) {
            return $this->searchWithDatabase($query, $filters, $perPage, $sort);
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->searchWithTypesense($query, $filters, $perPage, $sort);
            } catch (\Exception $e) {
                Log::warning('Typesense search failed, falling back to database', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->searchWithDatabase($query, $filters, $perPage, $sort);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function usesDefaultSearchCache(?string $query, array $filters, int $perPage, string $sort): bool
    {
        return in_array($query, [null, '', '0'], true)
            && $filters === []
            && $perPage === 12
            && $sort === 'time'
            && Paginator::resolveCurrentPage() === 1;
    }

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    private function cachedDefaultSearch(int $perPage): LengthAwarePaginator
    {
        /** @var array{ids: list<string>, total: int} $payload */
        $payload = app(SafeModelCache::class)->rememberPayload(
            key: 'default_events_search_v2',
            ttl: 60,
            resolver: function () use ($perPage): array {
                $paginator = $this->performSearch(null, [], $perPage, 'time');

                return [
                    'ids' => array_values(array_map(static fn (Event $event): string => (string) $event->getKey(), $paginator->items())),
                    'total' => $paginator->total(),
                ];
            },
        );

        if ($payload['ids'] === []) {
            return new Paginator(
                items: collect(),
                total: $payload['total'],
                perPage: $perPage,
                currentPage: 1,
                options: [
                    'path' => Paginator::resolveCurrentPath(),
                    'pageName' => 'page',
                    'query' => request()->query(),
                ],
            );
        }

        $events = Event::query()
            ->with($this->cardRelationships())
            ->whereKey($payload['ids'])
            ->get()
            ->keyBy(fn (Event $event): string => (string) $event->getKey());

        $orderedEvents = collect($payload['ids'])
            ->map(fn (string $eventId): ?Event => $events->get($eventId))
            ->filter()
            ->values();

        return new Paginator(
            items: $orderedEvents,
            total: $payload['total'],
            perPage: $perPage,
            currentPage: 1,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
                'query' => request()->query(),
            ],
        );
    }

    /**
     * Search with Typesense via Laravel Scout.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchWithTypesense(
        ?string $query,
        array $filters,
        int $perPage,
        string $sort
    ): LengthAwarePaginator {
        $search = Event::search($query ?? '')
            ->query(fn (Builder $builder) => $builder->with($this->cardRelationships()));

        $sortBy = match ($sort) {
            'relevance' => '_text_match:desc,starts_at:asc',
            'distance' => 'starts_at:asc',
            default => 'starts_at:asc',
        };

        $search->options([
            'filter_by' => implode(' && ', $this->buildTypesenseFilterParts($filters)),
            'sort_by' => $sortBy,
            'query_by' => 'title,speaker_names,institution_name',
        ]);

        return $search->paginate($perPage);
    }

    /**
     * Search with database fallback.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchWithDatabase(
        ?string $query,
        array $filters,
        int $perPage,
        string $sort
    ): LengthAwarePaginator {
        $normalizedQuery = $this->normalizeSearchQuery($query);

        if ($normalizedQuery === null) {
            $queryBuilder = $this->buildDatabaseQuery(null, $filters)
                ->with($this->cardRelationships());

            $this->applyDatabaseOrdering($queryBuilder, $sort, null);

            return $queryBuilder->paginate($perPage);
        }

        $directQuery = $this->buildDatabaseQuery(null, $filters)
            ->with($this->cardRelationships());
        $this->applyDirectSearch(
            $directQuery,
            $normalizedQuery,
            (bool) ($filters['search_include_institutions'] ?? true),
            (bool) ($filters['search_include_speakers'] ?? true),
            (bool) ($filters['search_include_references'] ?? true),
        );
        $this->applyDatabaseOrdering($directQuery, $sort, $normalizedQuery);

        $directMatches = $directQuery->paginate($perPage);

        if ($directMatches->total() > 0 || mb_strlen($normalizedQuery) < 3) {
            return $directMatches;
        }

        return $this->fuzzySearchWithDatabase($filters, $normalizedQuery, $perPage);
    }

    /**
     * Geo search for "near me" functionality.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    public function searchNearby(
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        if ($this->requiresDatabaseFiltering($filters)) {
            return $this->searchNearbyWithDatabase($lat, $lng, $radiusKm, $filters, $perPage);
        }

        if (config('scout.driver') === 'typesense') {
            try {
                $search = Event::search('');

                $search->query(fn (Builder $builder) => $builder->with($this->cardRelationships()));

                $filterBy = implode(' && ', [
                    "location:({$lat}, {$lng}, {$radiusKm} km)",
                    ...$this->buildTypesenseFilterParts($filters),
                ]);

                $search->options([
                    'filter_by' => $filterBy,
                    'sort_by' => "location({$lat}, {$lng}):asc",
                ]);

                return $search->paginate($perPage);
            } catch (\Exception $e) {
                Log::warning('Typesense geo search failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->searchNearbyWithDatabase($lat, $lng, $radiusKm, $filters, $perPage);
    }

    /**
     * Geo search constrained by a text query.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    public function searchNearbyWithQuery(
        ?string $query,
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $normalizedQuery = $this->normalizeSearchQuery($query);

        if ($normalizedQuery === null) {
            return $this->searchNearby($lat, $lng, $radiusKm, $filters, $perPage);
        }

        if ($this->requiresDatabaseFiltering($filters)) {
            return $this->searchNearbyWithDatabaseQuery($normalizedQuery, $lat, $lng, $radiusKm, $filters, $perPage);
        }

        if (config('scout.driver') === 'typesense' && $this->healthCheck->isAvailable()) {
            try {
                return $this->searchNearbyWithTypesenseQuery($normalizedQuery, $lat, $lng, $radiusKm, $filters, $perPage);
            } catch (\Exception $e) {
                Log::warning('Typesense geo query search failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->searchNearbyWithDatabaseQuery($normalizedQuery, $lat, $lng, $radiusKm, $filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    protected function buildTypesenseFilterParts(array $filters): array
    {
        $timeScope = $this->normalizeTimeScope($filters['time_scope'] ?? null);

        $filterParts = [
            'is_active:=true',
            'status:['.implode(', ', Event::PUBLIC_STATUSES).']',
            'visibility:public',
            'event_structure:!=parent_program',
        ];

        $startsAfterTimestamp = $this->startsAfterTimestamp($filters, $timeScope);

        if ($startsAfterTimestamp !== null) {
            $filterParts[] = '(ends_at:>='.$startsAfterTimestamp.'||starts_at:>='.$startsAfterTimestamp.')';
        }

        $startsOnLocalDateRange = $this->startsOnLocalDateRange($filters);

        if ($startsOnLocalDateRange !== null) {
            [$startsOnLocalDateStart, $startsOnLocalDateEnd] = $startsOnLocalDateRange;

            $filterParts[] = 'starts_at:>='.$startsOnLocalDateStart->timestamp;
            $filterParts[] = 'starts_at:<='.$startsOnLocalDateEnd->timestamp;
        }

        $startsBeforeTimestamp = $this->startsBeforeTimestamp($filters, $timeScope);

        if ($startsBeforeTimestamp !== null) {
            $filterParts[] = 'starts_at:<='.$startsBeforeTimestamp;
        }

        if (! empty($filters['district_id'])) {
            $filterParts[] = 'district_id:='.$filters['district_id'];
        }

        if (! empty($filters['country_id'])) {
            $filterParts[] = 'country_id:='.$filters['country_id'];
        }

        if (! empty($filters['state_id'])) {
            $filterParts[] = 'state_id:='.$filters['state_id'];
        }

        if (! empty($filters['subdistrict_id'])) {
            $filterParts[] = 'subdistrict_id:='.$filters['subdistrict_id'];
        }

        $languageCodes = $this->normalizeArrayFilter($filters['language_codes'] ?? null);

        if ($languageCodes !== []) {
            $filterParts[] = 'language_codes:['.implode(',', $languageCodes).']';
        }

        if (! empty($filters['event_type'])) {
            $eventTypes = $this->normalizeArrayFilter($filters['event_type']);

            if ($eventTypes !== []) {
                $filterParts[] = 'event_type:['.implode(',', $eventTypes).']';
            }
        }

        if (! empty($filters['event_format'])) {
            $eventFormats = $this->normalizeArrayFilter($filters['event_format']);

            if ($eventFormats !== []) {
                $filterParts[] = 'event_format:['.implode(',', $eventFormats).']';
            }
        }

        if (! empty($filters['gender'])) {
            $filterParts[] = 'gender:='.$filters['gender'];
        }

        if (! empty($filters['age_group'])) {
            $ageGroups = $this->normalizeArrayFilter($filters['age_group']);

            if ($ageGroups !== []) {
                $filterParts[] = 'age_group:['.implode(',', $ageGroups).']';
            }
        }

        $childrenAllowed = $this->normalizeBooleanFilter($filters['children_allowed'] ?? null);

        if ($childrenAllowed !== null) {
            $filterParts[] = 'children_allowed:='.($childrenAllowed ? 'true' : 'false');
        }

        if (! empty($filters['institution_id'])) {
            $filterParts[] = 'institution_id:='.$filters['institution_id'];
        }

        if (! empty($filters['venue_id'])) {
            $filterParts[] = 'venue_id:='.$filters['venue_id'];
        }

        if (! empty($filters['speaker_ids'])) {
            $speakerIds = $this->normalizeArrayFilter($filters['speaker_ids']);

            if ($speakerIds !== []) {
                $filterParts[] = 'speaker_ids:['.implode(',', $speakerIds).']';
            }
        }

        if (! empty($filters['key_person_roles'])) {
            $keyPersonRoles = $this->normalizeArrayFilter($filters['key_person_roles']);

            if ($keyPersonRoles !== []) {
                $filterParts[] = 'key_person_roles:['.implode(',', $keyPersonRoles).']';
            }
        }

        foreach (['person_in_charge_ids', 'moderator_ids', 'imam_ids', 'khatib_ids', 'bilal_ids'] as $roleSpecificFilter) {
            if (! empty($filters[$roleSpecificFilter])) {
                $roleSpecificIds = $this->normalizeArrayFilter($filters[$roleSpecificFilter]);

                if ($roleSpecificIds !== []) {
                    $filterParts[] = $roleSpecificFilter.':['.implode(',', $roleSpecificIds).']';
                }
            }
        }

        if (! empty($filters['topic_ids'])) {
            $topicIds = $this->normalizeArrayFilter($filters['topic_ids']);

            if ($topicIds !== []) {
                $filterParts[] = 'topic_ids:['.implode(',', $topicIds).']';
            }
        }

        if (! empty($filters['domain_tag_ids'])) {
            $domainTagIds = $this->normalizeArrayFilter($filters['domain_tag_ids']);

            if ($domainTagIds !== []) {
                $filterParts[] = 'domain_tag_ids:['.implode(',', $domainTagIds).']';
            }
        }

        if (! empty($filters['source_tag_ids'])) {
            $sourceTagIds = $this->normalizeArrayFilter($filters['source_tag_ids']);

            if ($sourceTagIds !== []) {
                $filterParts[] = 'source_tag_ids:['.implode(',', $sourceTagIds).']';
            }
        }

        if (! empty($filters['issue_tag_ids'])) {
            $issueTagIds = $this->normalizeArrayFilter($filters['issue_tag_ids']);

            if ($issueTagIds !== []) {
                $filterParts[] = 'issue_tag_ids:['.implode(',', $issueTagIds).']';
            }
        }

        if (! empty($filters['reference_ids'])) {
            $referenceIds = $this->expandedReferenceIdsForFiltering($filters['reference_ids']);

            if ($referenceIds !== []) {
                $filterParts[] = 'reference_ids:['.implode(',', $referenceIds).']';
            }
        }

        return $filterParts;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Event>
     */
    protected function buildDatabaseQuery(?string $query, array $filters): Builder
    {
        $timeScope = $this->normalizeTimeScope($filters['time_scope'] ?? null);

        $queryBuilder = Event::query()->active();
        $table = $queryBuilder->getModel()->getTable();

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

        if (! empty($filters['country_id'])) {
            $this->applyLocationAddressFilter($queryBuilder, 'country_id', $filters['country_id']);
        }

        if (! empty($filters['state_id'])) {
            $this->applyLocationAddressFilter($queryBuilder, 'state_id', $filters['state_id']);
        }

        if (! empty($filters['district_id'])) {
            $this->applyLocationAddressFilter($queryBuilder, 'district_id', $filters['district_id']);
        }

        if (! empty($filters['subdistrict_id'])) {
            $this->applyLocationAddressFilter($queryBuilder, 'subdistrict_id', $filters['subdistrict_id']);
        }

        $languageCodes = $this->normalizeArrayFilter($filters['language_codes'] ?? null);

        if ($languageCodes !== []) {
            $queryBuilder->whereHas('languages', function (Builder $languageQuery) use ($languageCodes) {
                $languageQuery->whereIn('code', $languageCodes);
            });
        }

        $eventTypes = $this->normalizeArrayFilter($filters['event_type'] ?? null);

        if ($eventTypes !== []) {
            $queryBuilder->where(function (Builder $eventTypeQuery) use ($eventTypes) {
                foreach ($eventTypes as $eventType) {
                    $eventTypeQuery->orWhereJsonContains('event_type', $eventType);
                }
            });
        }

        $eventFormats = $this->normalizeArrayFilter($filters['event_format'] ?? null);

        if ($eventFormats !== []) {
            $queryBuilder->whereIn('event_format', $eventFormats);
        }

        if (! empty($filters['gender'])) {
            $queryBuilder->where('gender', $filters['gender']);
        }

        $ageGroups = $this->normalizeArrayFilter($filters['age_group'] ?? null);

        if ($ageGroups !== []) {
            $queryBuilder->where(function (Builder $ageGroupQuery) use ($ageGroups) {
                foreach ($ageGroups as $ageGroup) {
                    $ageGroupQuery->orWhereJsonContains('age_group', $ageGroup);
                }
            });
        }

        $childrenAllowed = $this->normalizeBooleanFilter($filters['children_allowed'] ?? null);

        if ($childrenAllowed !== null) {
            $queryBuilder->where('children_allowed', $childrenAllowed);
        }

        $isMuslimOnly = $this->normalizeBooleanFilter($filters['is_muslim_only'] ?? null);

        if ($isMuslimOnly !== null) {
            $queryBuilder->where('is_muslim_only', $isMuslimOnly);
        }

        if (! empty($filters['institution_id'])) {
            $queryBuilder->where('institution_id', $filters['institution_id']);
        }

        if (! empty($filters['venue_id'])) {
            $queryBuilder->where('venue_id', $filters['venue_id']);
        }

        $speakerIds = $this->normalizeArrayFilter($filters['speaker_ids'] ?? null);

        if ($speakerIds !== []) {
            $queryBuilder->whereHas('speakers', function (Builder $speakerQuery) use ($speakerIds) {
                $speakerQuery->whereIn('speakers.id', $speakerIds);
            });
        }

        $keyPersonRoles = $this->normalizeKeyPersonRoles($filters['key_person_roles'] ?? null);

        if ($keyPersonRoles !== []) {
            $queryBuilder->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($keyPersonRoles): void {
                $keyPersonQuery->whereIn('role', $keyPersonRoles);
            });
        }

        foreach ([
            'person_in_charge_ids' => EventKeyPersonRole::PersonInCharge,
            'moderator_ids' => EventKeyPersonRole::Moderator,
            'imam_ids' => EventKeyPersonRole::Imam,
            'khatib_ids' => EventKeyPersonRole::Khatib,
            'bilal_ids' => EventKeyPersonRole::Bilal,
        ] as $filterKey => $role) {
            $roleSpecificIds = $this->normalizeArrayFilter($filters[$filterKey] ?? null);

            if ($roleSpecificIds === []) {
                continue;
            }

            $queryBuilder->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($roleSpecificIds, $role): void {
                $keyPersonQuery
                    ->where('role', $role->value)
                    ->whereIn('speaker_id', $roleSpecificIds);
            });
        }

        $personInChargeSearch = $this->normalizeTextFilter($filters['person_in_charge_search'] ?? null);

        if ($personInChargeSearch !== null) {
            $operator = $this->databaseLikeOperator();

            $queryBuilder->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($operator, $personInChargeSearch): void {
                $keyPersonQuery
                    ->where('role', EventKeyPersonRole::PersonInCharge->value)
                    ->where(function (Builder $personInChargeQuery) use ($operator, $personInChargeSearch): void {
                        $personInChargeQuery
                            ->where('name', $operator, "%{$personInChargeSearch}%")
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
            $queryBuilder->whereHas('tags', function (Builder $tagQuery) use ($topicIds) {
                $tagQuery
                    ->whereIn('tags.id', $topicIds)
                    ->whereIn('tags.type', ['discipline', 'issue'])
                    ->whereIn('tags.status', ['verified', 'pending']);
            });
        }

        $domainTagIds = $this->normalizeArrayFilter($filters['domain_tag_ids'] ?? null);

        if ($domainTagIds !== []) {
            $queryBuilder->whereHas('tags', function (Builder $tagQuery) use ($domainTagIds) {
                $tagQuery
                    ->whereIn('tags.id', $domainTagIds)
                    ->where('tags.type', 'domain')
                    ->whereIn('tags.status', ['verified', 'pending']);
            });
        }

        $sourceTagIds = $this->normalizeArrayFilter($filters['source_tag_ids'] ?? null);

        if ($sourceTagIds !== []) {
            $queryBuilder->whereHas('tags', function (Builder $tagQuery) use ($sourceTagIds) {
                $tagQuery
                    ->whereIn('tags.id', $sourceTagIds)
                    ->where('tags.type', 'source')
                    ->whereIn('tags.status', ['verified', 'pending']);
            });
        }

        $issueTagIds = $this->normalizeArrayFilter($filters['issue_tag_ids'] ?? null);

        if ($issueTagIds !== []) {
            $queryBuilder->whereHas('tags', function (Builder $tagQuery) use ($issueTagIds) {
                $tagQuery
                    ->whereIn('tags.id', $issueTagIds)
                    ->where('tags.type', 'issue')
                    ->whereIn('tags.status', ['verified', 'pending']);
            });
        }

        $referenceIds = $this->expandedReferenceIdsForFiltering($filters['reference_ids'] ?? null);

        $referenceAuthorSearches = $this->normalizeArrayFilter($filters['reference_author_search'] ?? null);

        if ($referenceAuthorSearches === [] && filled($filters['reference_author_search'] ?? null)) {
            $referenceAuthorSearches = [trim((string) $filters['reference_author_search'])];
        }

        foreach ($referenceAuthorSearches as $authorSearch) {
            if ($authorSearch === '') {
                continue;
            }

            $authorReferenceIds = $this->referenceSearch->publicSearchIds($authorSearch);
            $referenceIds = array_values(array_unique(array_merge($referenceIds, $authorReferenceIds)));
        }

        if ($referenceIds !== []) {
            $queryBuilder->whereHas('references', function (Builder $referenceQuery) use ($referenceIds): void {
                $referenceQuery->whereIn('references.id', $referenceIds);
            });
        }

        $timingMode = $this->normalizeTimingModeFilter($filters['timing_mode'] ?? null);
        $prayerTime = $this->normalizePrayerTimeFilter($filters['prayer_time'] ?? null);

        if ($prayerTime !== null && $timingMode !== TimingMode::Absolute->value) {
            $queryBuilder
                ->where('timing_mode', TimingMode::PrayerRelative->value)
                ->where(function (Builder $prayerQuery) use ($prayerTime): void {
                    $prayerQuery->where('prayer_display_text', $this->databaseLikeOperator(), "%{$prayerTime}%");

                    if (($prayerReference = $this->resolvePrayerReferenceFromFilter($prayerTime)) instanceof PrayerReference) {
                        $prayerQuery->orWhere('prayer_reference', $prayerReference->value);
                    }
                });
        }

        if ($timingMode !== null) {
            $queryBuilder->where('timing_mode', $timingMode);
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
            $queryBuilder->whereNotNull('event_url')->where('event_url', '!=', '');
        } elseif ($hasEventUrl === false) {
            $queryBuilder->where(function (Builder $eventUrlQuery): void {
                $eventUrlQuery->whereNull('event_url')->orWhere('event_url', '');
            });
        }

        $hasLiveUrl = $this->normalizeBooleanFilter($filters['has_live_url'] ?? null);

        if ($hasLiveUrl === true) {
            $queryBuilder->whereNotNull('live_url')->where('live_url', '!=', '');
        } elseif ($hasLiveUrl === false) {
            $queryBuilder->where(function (Builder $liveUrlQuery): void {
                $liveUrlQuery->whereNull('live_url')->orWhere('live_url', '');
            });
        }

        $hasEndTime = $this->normalizeBooleanFilter($filters['has_end_time'] ?? null);

        if ($hasEndTime === true) {
            $queryBuilder->whereNotNull('ends_at');
        } elseif ($hasEndTime === false) {
            $queryBuilder->whereNull('ends_at');
        }

        return $queryBuilder;
    }

    /**
     * @param  Builder<Event>  $queryBuilder
     */
    protected function applyDirectSearch(
        Builder $queryBuilder,
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

        // Resolve related entity IDs from the search term.
        $speakerIds = $includeSpeakers ? $this->speakerSearch->publicSearchIds($normalizedSearch) : [];
        $institutionIds = $includeInstitutions ? $this->institutionSearch->publicSearchIds($normalizedSearch) : [];
        $referenceIds = $includeReferences ? $this->referenceSearch->publicSearchIds($normalizedSearch) : [];

        $queryBuilder->where(function (Builder $nestedQuery) use ($normalizedSearch, $operator, $collapsedWildcardSearch, $searchTokens, $speakerIds, $institutionIds, $referenceIds, $includeSpeakers): void {
            // Title match (ranked first via applyDatabaseOrdering).
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

            // Institution name match.
            if ($institutionIds !== []) {
                $nestedQuery->orWhereIn('events.institution_id', $institutionIds);
            }

            // Key people: linked speaker IDs (all roles) + free-text name match.
            if ($includeSpeakers) {
                $nestedQuery->orWhereHas('keyPeople', function (Builder $keyPeopleQuery) use ($speakerIds, $normalizedSearch, $operator): void {
                    $keyPeopleQuery->where(function (Builder $inner) use ($speakerIds, $normalizedSearch, $operator): void {
                        $inner->where('event_key_people.name', $operator, "%{$normalizedSearch}%");

                        if ($speakerIds !== []) {
                            $inner->orWhereIn('event_key_people.speaker_id', $speakerIds);
                        }
                    });
                });
            }

            // Reference title/author match.
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

            $queryBuilder->orderByRaw(
                "CASE
                    WHEN events.title {$operator} ? THEN 1
                    ELSE 2
                END, events.starts_at ASC",
                ["%{$query}%"]
            );

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
        $normalizedSearch = $this->normalizeForSimilarity($search);

        if ($normalizedSearch === '') {
            $queryBuilder = $this->buildDatabaseQuery(null, $filters)
                ->with($this->cardRelationships())
                ->orderBy('starts_at', 'asc');

            return $queryBuilder->paginate($perPage);
        }

        $candidateQuery = $this->buildDatabaseQuery(null, $filters)
            ->select(['events.id', 'events.title'])
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyTitleCandidateFilter($query, $normalizedSearch))
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyTitleCandidateOrdering($query, $normalizedSearch));

        $rankedCandidates = $candidateQuery
            ->get()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'score' => $this->eventSimilarityScore($normalizedSearch, $event),
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
        $latitudeExpression = 'coalesce(venue_addresses.lat, institution_addresses.lat)';
        $longitudeExpression = 'coalesce(venue_addresses.lng, institution_addresses.lng)';
        $distanceSql = "(6371 * acos(cos(radians(?)) * cos(radians({$latitudeExpression})) * cos(radians({$longitudeExpression}) - radians(?)) + sin(radians(?)) * sin(radians({$latitudeExpression}))))";
        $venueMorphType = (new Venue)->getMorphClass();
        $institutionMorphType = (new Institution)->getMorphClass();

        $queryBuilder = $this->buildDatabaseQuery(null, $filters)
            ->leftJoin('addresses as venue_addresses', function ($join) use ($venueMorphType) {
                $join->on('venue_addresses.addressable_id', '=', 'events.venue_id')
                    ->where('venue_addresses.addressable_type', $venueMorphType);
            })
            ->leftJoin('addresses as institution_addresses', function ($join) use ($institutionMorphType) {
                $join->on('institution_addresses.addressable_id', '=', 'events.institution_id')
                    ->where('institution_addresses.addressable_type', $institutionMorphType);
            })
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
     * Search with Typesense via text query plus geo radius.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchNearbyWithTypesenseQuery(
        string $query,
        float $lat,
        float $lng,
        int $radiusKm,
        array $filters,
        int $perPage
    ): LengthAwarePaginator {
        $search = Event::search($query)
            ->query(fn (Builder $builder) => $builder->with($this->cardRelationships()));

        $filterBy = implode(' && ', [
            "location:({$lat}, {$lng}, {$radiusKm} km)",
            ...$this->buildTypesenseFilterParts($filters),
        ]);

        $search->options([
            'filter_by' => $filterBy,
            'sort_by' => "location({$lat}, {$lng}):asc,starts_at:asc",
            'query_by' => 'title,speaker_names,institution_name',
        ]);

        return $search->paginate($perPage);
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
        $latitudeExpression = 'coalesce(venue_addresses.lat, institution_addresses.lat)';
        $longitudeExpression = 'coalesce(venue_addresses.lng, institution_addresses.lng)';
        $distanceSql = "(6371 * acos(cos(radians(?)) * cos(radians({$latitudeExpression})) * cos(radians({$longitudeExpression}) - radians(?)) + sin(radians(?)) * sin(radians({$latitudeExpression}))))";
        $venueMorphType = (new Venue)->getMorphClass();
        $institutionMorphType = (new Institution)->getMorphClass();

        $queryBuilder = $this->buildDatabaseQuery(null, $filters);
        $this->applyDirectSearch($queryBuilder, $query);

        $queryBuilder
            ->leftJoin('addresses as venue_addresses', function ($join) use ($venueMorphType) {
                $join->on('venue_addresses.addressable_id', '=', 'events.venue_id')
                    ->where('venue_addresses.addressable_type', $venueMorphType);
            })
            ->leftJoin('addresses as institution_addresses', function ($join) use ($institutionMorphType) {
                $join->on('institution_addresses.addressable_id', '=', 'events.institution_id')
                    ->where('institution_addresses.addressable_type', $institutionMorphType);
            })
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
     * @param  Builder<Event>  $queryBuilder
     */
    protected function applyLocationAddressFilter(Builder $queryBuilder, string $column, mixed $value): void
    {
        $queryBuilder->where(function (Builder $locationQuery) use ($column, $value): void {
            $locationQuery
                ->whereHas('venue.address', function (Builder $addressQuery) use ($column, $value): void {
                    $addressQuery->where($column, $value);
                })
                ->orWhereHas('institution.address', function (Builder $addressQuery) use ($column, $value): void {
                    $addressQuery->where($column, $value);
                });
        });
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
    protected function expandedReferenceIdsForFiltering(mixed $value): array
    {
        $referenceIds = collect($this->normalizeArrayFilter($value))
            ->map(static fn (mixed $referenceId): string => (string) $referenceId)
            ->filter(static fn (string $referenceId): bool => $referenceId !== '')
            ->values()
            ->all();

        return Reference::expandRootReferenceIdsForFiltering($referenceIds);
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
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  Builder<Event>  $queryBuilder
     */
    protected function applyAbsoluteTimeRangeFilter(
        Builder $queryBuilder,
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

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function requiresDatabaseFiltering(array $filters): bool
    {
        return $this->normalizePrayerTimeFilter($filters['prayer_time'] ?? null) !== null
            || $this->normalizeArrayFilter($filters['language_codes'] ?? null) !== []
            || $this->normalizeTextFilter($filters['person_in_charge_search'] ?? null) !== null
            || $this->normalizeTimingModeFilter($filters['timing_mode'] ?? null) !== null
            || $this->normalizeTimeFilter($filters['starts_time_from'] ?? null) !== null
            || $this->normalizeTimeFilter($filters['starts_time_until'] ?? null) !== null
            || filled($filters['venue_id'] ?? null)
            || $this->normalizeBooleanFilter($filters['is_muslim_only'] ?? null) !== null
            || $this->normalizeBooleanFilter($filters['has_event_url'] ?? null) !== null
            || $this->normalizeBooleanFilter($filters['has_live_url'] ?? null) !== null
            || $this->normalizeBooleanFilter($filters['has_end_time'] ?? null) !== null;
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
        $safeOffsetMinutes = $offsetMinutes;

        return match ($this->databaseDriver()) {
            'pgsql' => "to_char(events.starts_at + interval '{$safeOffsetMinutes} minutes', 'HH24:MI')",
            'mysql', 'mariadb' => "DATE_FORMAT(DATE_ADD(events.starts_at, INTERVAL {$safeOffsetMinutes} MINUTE), '%H:%i')",
            default => "strftime('%H:%M', datetime(events.starts_at, '{$safeOffsetMinutes} minutes'))",
        };
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

    private function normalizeForSimilarity(string $value): string
    {
        return (string) Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim();
    }

    /**
     * @param  Builder<Event>  $queryBuilder
     * @return Builder<Event>
     */
    private function applyFuzzyTitleCandidateFilter(Builder $queryBuilder, string $normalizedSearch): Builder
    {
        $patterns = $this->fuzzyCandidatePatterns($normalizedSearch);

        if ($patterns === []) {
            return $queryBuilder->limit($this->fuzzyCandidateLimit());
        }

        $operator = $this->databaseLikeOperator();

        $queryBuilder->where(function (Builder $candidateQuery) use ($operator, $patterns): void {
            foreach ($patterns as $index => $pattern) {
                $method = $index === 0 ? 'where' : 'orWhere';

                $candidateQuery->{$method}('events.title', $operator, $pattern);
            }
        });

        return $queryBuilder->limit($this->fuzzyCandidateLimit());
    }

    /**
     * @param  Builder<Event>  $queryBuilder
     * @return Builder<Event>
     */
    private function applyFuzzyTitleCandidateOrdering(Builder $queryBuilder, string $normalizedSearch): Builder
    {
        return $queryBuilder
            ->orderByRaw(
                "case when lower(coalesce(events.title, '')) = ? then 0 when lower(coalesce(events.title, '')) like ? then 1 when lower(coalesce(events.title, '')) like ? then 2 else 3 end",
                [$normalizedSearch, $normalizedSearch.'%', '%'.$normalizedSearch.'%']
            )
            ->orderByRaw("length(coalesce(events.title, ''))")
            ->orderBy('events.title')
            ->orderBy('events.starts_at')
            ->orderBy('events.id');
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

    private function fuzzyCandidateLimit(): int
    {
        return 250;
    }

    private function similarityScore(string $search, string $candidate): float
    {
        if ($search === '' || $candidate === '') {
            return 0.0;
        }

        $distance = levenshtein($search, $candidate);
        $maxLength = max(mb_strlen($search), mb_strlen($candidate));
        $distanceScore = $maxLength > 0 ? 1 - ($distance / $maxLength) : 0.0;

        similar_text($search, $candidate, $similarityPercent);
        $similarityScore = $similarityPercent / 100;

        return max($distanceScore, $similarityScore);
    }

    private function eventSimilarityScore(string $normalizedSearch, Event $event): float
    {
        $title = trim((string) $event->title);

        if ($title === '') {
            return 0.0;
        }

        $normalizedCandidate = $this->normalizeForSimilarity($title);

        if ($normalizedCandidate === '') {
            return 0.0;
        }

        $scoreCandidates = [];
        $scoreCandidates[] = $this->similarityScore($normalizedSearch, $normalizedCandidate);

        /** @var list<string> $candidateTokens */
        $candidateTokens = array_values(array_filter(
            explode(' ', $normalizedCandidate),
            static fn (string $token): bool => mb_strlen($token) >= 2
        ));

        foreach ($candidateTokens as $token) {
            $scoreCandidates[] = $this->similarityScore($normalizedSearch, $token);
        }

        return max($scoreCandidates);
    }
}
