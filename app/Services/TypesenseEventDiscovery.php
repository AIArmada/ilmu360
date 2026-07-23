<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventDiscoveryAdapter;
use App\Data\EventDiscoveryCriteria;
use App\Models\Event;
use App\Models\Reference;
use App\Support\EventDiscovery\EventDiscoveryFilterSet;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TypesenseEventDiscovery implements EventDiscoveryAdapter
{
    public function __construct(
        private readonly EventDiscoveryFilterSet $filterSet,
        private readonly EventCategoryCatalog $categoryCatalog,
    ) {}

    /** @return LengthAwarePaginator<int, Event> */
    public function search(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchWithTypesense($criteria->text, $criteria->filters, $criteria->perPage, $criteria->sort);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearby(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchNearbyWithTypesense($criteria);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchNearbyWithTypesenseQuery(
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
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchNearbyWithTypesense(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        $lat = $criteria->latitude ?? 0.0;
        $lng = $criteria->longitude ?? 0.0;
        $radiusKm = $criteria->radiusKm ?? 0.0;
        $search = Event::search('');

        $search->query(fn (Builder $builder) => $builder->with($this->cardRelationships()));
        $search->options([
            'filter_by' => implode(' && ', [
                "location:({$lat}, {$lng}, {$radiusKm} km)",
                ...$this->buildTypesenseFilterParts($criteria->filters),
            ]),
            'sort_by' => "location({$lat}, {$lng}):asc",
        ]);

        return $search->paginate($criteria->perPage);
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
     * @return list<string>
     */
    protected function buildTypesenseFilterParts(array $filters): array
    {
        $timeScope = $this->normalizeTimeScope($filters['time_scope'] ?? null);

        $filterParts = [
            'status:['.implode(', ', Event::PUBLIC_STATUSES).']',
            'visibility:public',
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

        return Reference::expandRootReferenceIdsForFiltering($referenceIds);
    }
}
