<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;
use App\Features\Discovery\Api\Data\EventSearchHitIds;
use App\Features\Events\Api\Data\EventDiscoveryPage;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\EventDiscoveryReader;
use App\Features\Events\Models\Event;
use App\Features\References\Api\ReferenceQueries;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Laravel\Scout\Builder as ScoutBuilder;

class TypesenseEventDiscovery implements EventDiscoveryReader
{
    public function __construct(
        private readonly EventDiscoveryFilterSet $filterSet,
        private readonly EventCategoryCatalog $categoryCatalog,
        private readonly ReferenceQueries $referenceQueries,
    ) {}

    public function search(EventDiscoveryCriteria $criteria): EventDiscoveryPage
    {
        return EventDiscoveryPageProjector::fromPaginator($this->searchWithTypesense($criteria->text, $criteria->filters, $criteria->perPage, $criteria->sort));
    }

    public function searchIds(EventDiscoveryCriteria $criteria): EventSearchHitIds
    {
        return $this->toHitIdsFromScoutPaginator(
            $this->buildTextSearch($criteria->text, $criteria->filters, $criteria->sort),
            $criteria->perPage,
        );
    }

    public function nearby(EventDiscoveryCriteria $criteria): EventDiscoveryPage
    {
        return EventDiscoveryPageProjector::fromPaginator($this->searchNearbyWithTypesense($criteria));
    }

    public function nearbyIds(EventDiscoveryCriteria $criteria): EventSearchHitIds
    {
        return $this->toHitIdsFromScoutPaginator(
            $this->buildNearbySearch($criteria),
            $criteria->perPage,
        );
    }

    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): EventDiscoveryPage
    {
        return EventDiscoveryPageProjector::fromPaginator($this->searchNearbyWithTypesenseQuery(
            $criteria->text ?? '',
            $criteria->latitude ?? 0.0,
            $criteria->longitude ?? 0.0,
            (int) ($criteria->radiusKm ?? 0.0),
            $criteria->filters,
            $criteria->perPage,
        ));
    }

    public function nearbyWithQueryIds(EventDiscoveryCriteria $criteria): EventSearchHitIds
    {
        return $this->toHitIdsFromScoutPaginator(
            $this->buildNearbyQuerySearch(
                $criteria->text ?? '',
                $criteria->latitude ?? 0.0,
                $criteria->longitude ?? 0.0,
                (int) ($criteria->radiusKm ?? 0.0),
                $criteria->filters,
            ),
            $criteria->perPage,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchWithTypesense(
        ?string $query,
        array $filters,
        int $perPage,
        string $sort
    ): LengthAwarePaginator {
        return $this->buildTextSearch($query, $filters, $sort)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return ScoutBuilder<Event>
     */
    private function buildTextSearch(?string $query, array $filters, string $sort): ScoutBuilder
    {
        $search = Event::search($query ?? '')
            ->query(fn (Builder $builder): Builder => $this->constrainCurrentPublicState($builder));

        $sortBy = match ($sort) {
            'relevance' => '_text_match:desc,starts_at:asc',
            'distance' => 'starts_at:asc',
            'popular' => 'saves_count:desc,starts_at:asc',
            default => 'starts_at:asc',
        };

        $search->options([
            'filter_by' => implode(' && ', $this->buildTypesenseFilterParts($filters)),
            'sort_by' => $sortBy,
            'query_by' => 'title,person_names,institution_name',
        ]);

        return $search;
    }

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchNearbyWithTypesense(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->buildNearbySearch($criteria)->paginate($criteria->perPage);
    }

    /**
     * @return ScoutBuilder<Event>
     */
    private function buildNearbySearch(EventDiscoveryCriteria $criteria): ScoutBuilder
    {
        $lat = $criteria->latitude ?? 0.0;
        $lng = $criteria->longitude ?? 0.0;
        $radiusKm = $criteria->radiusKm ?? 0.0;
        $search = Event::search('');

        $search->query(fn (Builder $builder): Builder => $this->constrainCurrentPublicState($builder));
        $search->options([
            'filter_by' => implode(' && ', [
                "location:({$lat}, {$lng}, {$radiusKm} km)",
                ...$this->buildTypesenseFilterParts($criteria->filters),
            ]),
            'sort_by' => "location({$lat}, {$lng}):asc",
        ]);

        return $search;
    }

    /**
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
        return $this->buildNearbyQuerySearch($query, $lat, $lng, $radiusKm, $filters)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return ScoutBuilder<Event>
     */
    private function buildNearbyQuerySearch(
        string $query,
        float $lat,
        float $lng,
        int $radiusKm,
        array $filters,
    ): ScoutBuilder {
        $search = Event::search($query)
            ->query(fn (Builder $builder): Builder => $this->constrainCurrentPublicState($builder));

        $filterBy = implode(' && ', [
            "location:({$lat}, {$lng}, {$radiusKm} km)",
            ...$this->buildTypesenseFilterParts($filters),
        ]);

        $search->options([
            'filter_by' => $filterBy,
            'sort_by' => "location({$lat}, {$lng}):asc,starts_at:asc",
            'query_by' => 'title,person_names,institution_name',
        ]);

        return $search;
    }

    /**
     * Extract IDs and distances without full model hydration, keeping the
     * lightweight public-state freshness filter in Typesense hit order.
     *
     * @param  ScoutBuilder<Event>  $search
     */
    private function toHitIdsFromScoutPaginator(ScoutBuilder $search, int $perPage): EventSearchHitIds
    {
        $rawPaginator = $search->paginateRaw($perPage);
        $rawItems = $rawPaginator->items();

        if (isset($rawItems['hits']) && is_array($rawItems['hits'])) {
            /** @var list<string> $candidateIds */
            $candidateIds = [];
            /** @var array<string, float> $candidateDistances */
            $candidateDistances = [];

            foreach ($rawItems['hits'] as $hit) {
                if (! is_array($hit)) {
                    continue;
                }

                $documentId = $hit['document']['id'] ?? null;

                if (! is_string($documentId) || $documentId === '') {
                    continue;
                }

                $candidateIds[] = $documentId;
                $meters = $hit['geo_distance_meters']['location'] ?? null;

                if (is_numeric($meters)) {
                    $candidateDistances[$documentId] = round(((float) $meters) / 1000.0, 3);
                }
            }

            if ($candidateIds === []) {
                return new EventSearchHitIds(eventIds: [], distances: [], total: $rawPaginator->total());
            }

            $validIdSet = array_flip(
                $this->constrainCurrentPublicState(Event::query()->whereKey($candidateIds))
                    ->pluck('events.id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all()
            );

            $eventIds = array_values(array_filter(
                $candidateIds,
                static fn (string $id): bool => isset($validIdSet[$id]),
            ));
            $distances = array_intersect_key($candidateDistances, $validIdSet);
            $droppedCount = count($candidateIds) - count($eventIds);

            return new EventSearchHitIds(
                eventIds: $eventIds,
                distances: $distances,
                total: max(0, $rawPaginator->total() - $droppedCount),
            );
        }

        if (isset($rawItems['results']) && is_array($rawItems['results'])) {
            $models = $rawItems['results'];
            $total = (int) ($rawItems['total'] ?? count($models));
        } else {
            $models = $rawItems;
            $total = $rawPaginator->total();
        }

        /** @var list<string> $eventIds */
        $eventIds = [];
        /** @var array<string, float> $distances */
        $distances = [];

        foreach ($models as $event) {
            if (! $event instanceof Event) {
                continue;
            }

            $eventId = (string) $event->getKey();
            $eventIds[] = $eventId;
            $distance = $event->getAttributes()['distance_km'] ?? null;

            if (is_numeric($distance)) {
                $distances[$eventId] = round((float) $distance, 3);
            }
        }

        return new EventSearchHitIds(
            eventIds: $eventIds,
            distances: $distances,
            total: $total,
        );
    }

    /**
     * Reapply current public parent and occurrence state during model
     * rehydration. Typesense filters indexed values, which may be stale.
     *
     * @param  Builder<Event>  $builder
     * @return Builder<Event>
     */
    private function constrainCurrentPublicState(Builder $builder): Builder
    {
        return $builder
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereNotNull('events.published_at')
            ->whereHas('occurrences', function (Builder $occurrences): void {
                $occurrences
                    ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                    ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES);
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    protected function buildTypesenseFilterParts(array $filters): array
    {
        $timeScope = $this->normalizeTimeScope($filters['time_scope'] ?? null);

        $filterParts = [
            'status:=['.implode(',', Event::PUBLIC_STATUSES).']',
            'visibility:public',
        ];

        $startsAfterTimestamp = $this->startsAfterTimestamp($filters, $timeScope);

        if ($startsAfterTimestamp !== null) {
            $filterParts[] = 'ends_at:>='.$startsAfterTimestamp;
        }

        $startsOnLocalDateRange = $this->startsOnLocalDateRange($filters);

        if ($startsOnLocalDateRange !== null) {
            [$startsOnLocalDateStart, $startsOnLocalDateEnd] = $startsOnLocalDateRange;

            $filterParts[] = 'starts_at:>='.$startsOnLocalDateStart->timestamp;
            $filterParts[] = 'starts_at:<='.$startsOnLocalDateEnd->timestamp;
        }

        $startsBeforeTimestamp = $this->startsBeforeTimestamp($filters);

        if ($startsBeforeTimestamp !== null) {
            $filterParts[] = 'starts_at:<='.$startsBeforeTimestamp;
        } elseif ($timeScope === 'past') {
            $filterParts[] = 'ends_at:<'.now()->timestamp;
        }

        $filterParts = [
            ...$filterParts,
            ...$this->filterSet->typesenseLocationFilterParts($this->filterSet->location($filters)),
        ];

        $languageCodes = $this->normalizeArrayFilter($filters['language_codes'] ?? null);

        if ($languageCodes !== []) {
            $filterParts[] = 'language_codes:['.implode(',', $languageCodes).']';
        }

        if (! empty($filters['event_category_ids'])) {
            $categoryIds = $this->categoryCatalog->descendantIds($this->normalizeArrayFilter($filters['event_category_ids']));

            if ($categoryIds !== []) {
                $filterParts[] = 'taxonomy_term_ids:['.implode(',', $categoryIds).']';
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

        foreach (['children_allowed', 'is_muslim_only', 'has_event_url', 'has_live_url', 'has_end_time'] as $booleanField) {
            $booleanValue = $this->normalizeBooleanFilter($filters[$booleanField] ?? null);

            if ($booleanValue !== null) {
                $filterParts[] = $booleanField.':='.($booleanValue ? 'true' : 'false');
            }
        }

        $timingMode = $filters['timing_mode'] ?? null;

        if (in_array($timingMode, [TimingMode::Absolute->value, TimingMode::PrayerRelative->value], true)) {
            $filterParts[] = 'timing_mode:='.$timingMode;
        }

        if (! empty($filters['institution_id'])) {
            $filterParts[] = 'institution_id:='.$filters['institution_id'];
        }

        if (! empty($filters['venue_id'])) {
            $filterParts[] = 'venue_id:='.$filters['venue_id'];
        }

        if (! empty($filters['person_ids'])) {
            $personIds = $this->normalizeArrayFilter($filters['person_ids']);

            if ($personIds !== []) {
                $filterParts[] = 'person_ids:['.implode(',', $personIds).']';
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

        if (! empty($filters['discipline_tag_ids'])) {
            $disciplineTagIds = $this->normalizeArrayFilter($filters['discipline_tag_ids']);

            if ($disciplineTagIds !== []) {
                $filterParts[] = 'discipline_tag_ids:['.implode(',', $disciplineTagIds).']';
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
            $requestedReferenceIds = $this->uuidFilterValues($filters['reference_ids']);
            $referenceIds = $this->expandedReferenceIdsForFiltering($filters['reference_ids']);

            if ($referenceIds === []) {
                $referenceIds = $requestedReferenceIds;
            }

            if ($referenceIds !== []) {
                $filterParts[] = 'reference_ids:['.implode(',', $referenceIds).']';
            }
        }

        return $filterParts;
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
    protected function startsBeforeTimestamp(array $filters): ?int
    {
        $startsBefore = $this->startsBeforeDateTime($filters);

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
    protected function startsBeforeDateTime(array $filters): ?CarbonInterface
    {
        return $this->parseDateFilter($filters['starts_before'] ?? null, true);
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

        return $this->referenceQueries->expandReferenceIdsForFiltering($referenceIds);
    }
}
