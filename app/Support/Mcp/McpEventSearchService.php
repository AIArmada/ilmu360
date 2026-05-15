<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use App\Data\Api\Frontend\Search\EventListData;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Services\EventSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;

class McpEventSearchService
{
    /**
     * @var list<string>
     */
    private const array ARRAY_FILTER_KEYS = [
        'language_codes',
        'event_type',
        'age_group',
        'event_format',
        'speaker_ids',
        'key_person_roles',
        'person_in_charge_ids',
        'moderator_ids',
        'imam_ids',
        'khatib_ids',
        'bilal_ids',
        'topic_ids',
        'domain_tag_ids',
        'source_tag_ids',
        'issue_tag_ids',
        'reference_ids',
        'reference_author_search',
    ];

    /**
     * @var list<string>
     */
    private const array FILTER_KEYS = [
        'country_id',
        'state_id',
        'district_id',
        'subdistrict_id',
        'language_codes',
        'event_type',
        'gender',
        'age_group',
        'children_allowed',
        'is_muslim_only',
        'institution_id',
        'venue_id',
        'speaker_ids',
        'key_person_roles',
        'person_in_charge_ids',
        'person_in_charge_search',
        'moderator_ids',
        'imam_ids',
        'khatib_ids',
        'bilal_ids',
        'topic_ids',
        'domain_tag_ids',
        'source_tag_ids',
        'issue_tag_ids',
        'reference_ids',
        'starts_after',
        'starts_before',
        'time_scope',
        'prayer_time',
        'timing_mode',
        'starts_time_from',
        'starts_time_until',
        'event_format',
        'has_event_url',
        'has_live_url',
        'has_end_time',
        'search_include_institutions',
        'search_include_speakers',
        'search_include_references',
        'reference_author_search',
    ];

    public function __construct(
        private readonly EventSearchService $eventSearchService,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function search(array $arguments): array
    {
        $query = $this->normalizeOptionalString($arguments['query'] ?? null);
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($arguments['per_page'] ?? 12)));

        $sort = (string) ($arguments['sort'] ?? 'time');

        if (! in_array($sort, ['time', 'relevance', 'distance'], true)) {
            $sort = 'time';
        }

        $lat = $this->normalizeOptionalFloat($arguments['lat'] ?? null);
        $lng = $this->normalizeOptionalFloat($arguments['lng'] ?? null);
        $radiusKm = max(1, min(1000, (int) ($arguments['radius_km'] ?? 15)));

        if ($sort === 'distance' && ($lat === null || $lng === null)) {
            $sort = 'time';
        }

        $filters = $this->normalizeFilters($arguments);

        /** @var LengthAwarePaginator<int, Event> $results */
        $results = $this->withCurrentPage($page, function () use ($lat, $lng, $radiusKm, $filters, $perPage, $query, $sort): LengthAwarePaginator {
            if ($lat !== null && $lng !== null) {
                return $this->eventSearchService->searchNearby(
                    lat: $lat,
                    lng: $lng,
                    radiusKm: $radiusKm,
                    filters: $filters,
                    perPage: $perPage,
                );
            }

            return $this->eventSearchService->search(
                query: $query,
                filters: $filters,
                perPage: $perPage,
                sort: $sort,
            );
        });

        return [
            'data' => collect($results->items())
                ->map(function ($event): array {
                    $item = EventListData::fromModel($event)->toArray();
                    $distanceKm = $event->getAttributes()['distance_km'] ?? null;

                    if (is_numeric($distanceKm)) {
                        $item['distance_km'] = round((float) $distanceKm, 3);
                    }

                    return $item;
                })
                ->values()
                ->all(),
            'meta' => [
                'search' => [
                    'query' => $query,
                    'sort' => $sort,
                    'nearby' => [
                        'enabled' => $lat !== null && $lng !== null,
                        'lat' => $lat,
                        'lng' => $lng,
                        'radius_km' => $radiusKm,
                    ],
                    'filters' => $filters,
                ],
                'pagination' => [
                    'page' => $results->currentPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total(),
                    'last_page' => $results->lastPage(),
                    'from' => $results->firstItem(),
                    'to' => $results->lastItem(),
                    'has_more_pages' => $results->hasMorePages(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationRules(): array
    {
        return [
            'query' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:time,relevance,distance'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'country_id' => ['sometimes', 'nullable'],
            'state_id' => ['sometimes', 'nullable'],
            'district_id' => ['sometimes', 'nullable'],
            'subdistrict_id' => ['sometimes', 'nullable'],
            'language_codes' => ['sometimes', 'nullable', 'array'],
            'language_codes.*' => ['string'],
            'event_type' => ['sometimes', 'nullable', 'array'],
            'event_type.*' => ['string'],
            'gender' => ['sometimes', 'nullable', 'string'],
            'age_group' => ['sometimes', 'nullable', 'array'],
            'age_group.*' => ['string'],
            'children_allowed' => ['sometimes', 'nullable', 'boolean'],
            'is_muslim_only' => ['sometimes', 'nullable', 'boolean'],
            'institution_id' => ['sometimes', 'nullable', 'string'],
            'venue_id' => ['sometimes', 'nullable', 'string'],
            'speaker_ids' => ['sometimes', 'nullable', 'array'],
            'speaker_ids.*' => ['string'],
            'key_person_roles' => ['sometimes', 'nullable', 'array'],
            'key_person_roles.*' => ['string'],
            'person_in_charge_ids' => ['sometimes', 'nullable', 'array'],
            'person_in_charge_ids.*' => ['string'],
            'person_in_charge_search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'moderator_ids' => ['sometimes', 'nullable', 'array'],
            'moderator_ids.*' => ['string'],
            'imam_ids' => ['sometimes', 'nullable', 'array'],
            'imam_ids.*' => ['string'],
            'khatib_ids' => ['sometimes', 'nullable', 'array'],
            'khatib_ids.*' => ['string'],
            'bilal_ids' => ['sometimes', 'nullable', 'array'],
            'bilal_ids.*' => ['string'],
            'topic_ids' => ['sometimes', 'nullable', 'array'],
            'topic_ids.*' => ['string'],
            'domain_tag_ids' => ['sometimes', 'nullable', 'array'],
            'domain_tag_ids.*' => ['string'],
            'source_tag_ids' => ['sometimes', 'nullable', 'array'],
            'source_tag_ids.*' => ['string'],
            'issue_tag_ids' => ['sometimes', 'nullable', 'array'],
            'issue_tag_ids.*' => ['string'],
            'reference_ids' => ['sometimes', 'nullable', 'array'],
            'reference_ids.*' => ['string'],
            'starts_after' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'starts_before' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'time_scope' => ['sometimes', 'nullable', 'string', 'in:upcoming,past,all'],
            'prayer_time' => ['sometimes', 'nullable', 'string'],
            'timing_mode' => ['sometimes', 'nullable', 'string', 'in:'.TimingMode::Absolute->value.','.TimingMode::PrayerRelative->value],
            'starts_time_from' => ['sometimes', 'nullable', 'date_format:H:i'],
            'starts_time_until' => ['sometimes', 'nullable', 'date_format:H:i'],
            'event_format' => ['sometimes', 'nullable', 'array'],
            'event_format.*' => ['string'],
            'has_event_url' => ['sometimes', 'nullable', 'boolean'],
            'has_live_url' => ['sometimes', 'nullable', 'boolean'],
            'has_end_time' => ['sometimes', 'nullable', 'boolean'],
            'search_include_institutions' => ['sometimes', 'nullable', 'boolean'],
            'search_include_speakers' => ['sometimes', 'nullable', 'boolean'],
            'search_include_references' => ['sometimes', 'nullable', 'boolean'],
            'reference_author_search' => ['sometimes', 'nullable', 'array'],
            'reference_author_search.*' => ['string', 'max:255'],
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $stringArray = $schema->array()->items($schema->string());

        return [
            'query' => $schema->string()->nullable()->description(
                'Optional keyword search across event titles, descriptions, and associated speaker/institution names.'
            ),
            'page' => $schema->integer()->default(1)->description('Page number for pagination. Starts at 1.'),
            'per_page' => $schema->integer()->default(12)->description('Number of results per page. Max 100.'),
            'sort' => $schema->string()->default('time')->enum(['time', 'relevance', 'distance'])->description(
                'Sort order. "time" = chronological (upcoming first). "relevance" = keyword relevance (requires query). "distance" = nearest first (requires lat + lng).'
            ),
            'lat' => $schema->number()->nullable()->description(
                'Latitude for geo/nearby search. Required when sort=distance. Range: -90 to 90.'
            ),
            'lng' => $schema->number()->nullable()->description(
                'Longitude for geo/nearby search. Required when sort=distance. Range: -180 to 180.'
            ),
            'radius_km' => $schema->integer()->default(15)->description(
                'Search radius in kilometres when lat+lng are provided. Default 15, max 1000.'
            ),
            'country_id' => $schema->string()->nullable()->description(
                'Integer ID of the country (from the geography tables). Use the get-countries tool or geography endpoints to obtain valid IDs.'
            ),
            'state_id' => $schema->string()->nullable()->description(
                'Integer ID of the state/region within the country.'
            ),
            'district_id' => $schema->string()->nullable()->description(
                'Integer ID of the district within the state.'
            ),
            'subdistrict_id' => $schema->string()->nullable()->description(
                'Integer ID of the subdistrict within the district.'
            ),
            'language_codes' => $stringArray->description(
                'Array of BCP-47 language codes. Example: ["ms", "en", "ar"]. Events that are conducted in any of the given languages will be returned.'
            ),
            'event_type' => $stringArray->description(
                'Array of event type values to filter by. Valid values: kuliah_ceramah, kelas_daurah, talim, forum, seminar_konvensyen, tazkirah, khutbah_jumaat, qiamullail, tahlil, solat_hajat, zikir, selawat, doa_selamat, bacaan_yasin, khatam_quran, tilawah, hafazan_quran, gotong_royong, kenduri, iftar, sahur, korban, aqiqah, other.'
            ),
            'gender' => $schema->string()->nullable()->description(
                'Audience gender restriction. Valid values: "male", "female". Omit to include all genders.'
            ),
            'age_group' => $stringArray->description(
                'Array of target age groups. Valid values: all_ages, adults, youth, children, warga_emas (seniors). Events matching any selected group are returned.'
            ),
            'children_allowed' => $schema->boolean()->nullable()->description(
                'Pass true to show only events that explicitly allow children. Pass false to exclude them. Omit for no restriction.'
            ),
            'is_muslim_only' => $schema->boolean()->nullable()->description(
                'Pass true to return only Muslim-only events. Pass false to return only open events. Omit for no restriction.'
            ),
            'institution_id' => $schema->string()->nullable()->description(
                'UUID of the organising institution. Restricts results to events hosted by that institution.'
            ),
            'venue_id' => $schema->string()->nullable()->description(
                'UUID of the venue. Restricts results to events held at that specific venue.'
            ),
            'speaker_ids' => $stringArray->description(
                'Array of speaker UUIDs. Returns events where any of the given speakers appear as a key person (any role).'
            ),
            'key_person_roles' => $stringArray->description(
                'Array of key-person role values to filter by. Valid values: speaker, moderator, person_in_charge, imam, khatib, bilal. Use with speaker_ids to narrow by role.'
            ),
            'person_in_charge_ids' => $stringArray->description(
                'Array of speaker UUIDs who are assigned the person_in_charge (PIC/coordinator) role on the event.'
            ),
            'person_in_charge_search' => $schema->string()->nullable()->description(
                'Text search on the name of the person in charge / event coordinator.'
            ),
            'moderator_ids' => $stringArray->description('Array of speaker UUIDs who are the event moderator(s).'),
            'imam_ids' => $stringArray->description('Array of speaker UUIDs who are the event imam(s).'),
            'khatib_ids' => $stringArray->description('Array of speaker UUIDs who are the event khatib(s).'),
            'bilal_ids' => $stringArray->description('Array of speaker UUIDs who are the event bilal(s).'),
            'topic_ids' => $stringArray->description(
                'Array of topic UUIDs. Returns events tagged with any of the given topics.'
            ),
            'domain_tag_ids' => $stringArray->description(
                'Array of domain tag UUIDs (e.g. Aqidah, Syariah, Akhlak). Returns events tagged with any of the given domain tags.'
            ),
            'source_tag_ids' => $stringArray->description(
                'Array of source tag UUIDs (e.g. Quran, Hadith, Turath). Returns events tagged with any of the given source tags.'
            ),
            'issue_tag_ids' => $stringArray->description(
                'Array of issue/contemporary-theme tag UUIDs. Returns events tagged with any of the given issue tags.'
            ),
            'reference_ids' => $stringArray->description(
                'Array of reference (book/kitab) UUIDs. Returns events associated with any of the given references.'
            ),
            'starts_after' => $schema->string()->nullable()->description(
                'Return events that start on or after this date. Format: Y-m-d (e.g. 2025-06-01). Interpreted in the viewer\'s local timezone.'
            ),
            'starts_before' => $schema->string()->nullable()->description(
                'Return events that start on or before this date. Format: Y-m-d (e.g. 2025-12-31). Interpreted in the viewer\'s local timezone.'
            ),
            'time_scope' => $schema->string()->default('upcoming')->enum(['upcoming', 'past', 'all'])->description(
                'Temporal scope. "upcoming" (default) = future events. "past" = already-ended events. "all" = no time restriction.'
            ),
            'prayer_time' => $schema->string()->nullable()->description(
                'Filter by prayer-relative timing label when timing_mode=prayer_relative. Valid values: fajr, dhuhr, asr, maghrib, isha, friday_prayer. Also accepts free-text labels like "Selepas Maghrib" for display-text matching.'
            ),
            'timing_mode' => $schema->string()->nullable()->enum([
                TimingMode::Absolute->value,
                TimingMode::PrayerRelative->value,
            ])->description(
                '"absolute" = filter by clock time using starts_time_from/starts_time_until. "prayer_relative" = filter by prayer-relative slot using prayer_time.'
            ),
            'starts_time_from' => $schema->string()->nullable()->description(
                '24-hour clock time lower bound (HH:MM). Only effective when timing_mode=absolute. Example: "20:00".'
            ),
            'starts_time_until' => $schema->string()->nullable()->description(
                '24-hour clock time upper bound (HH:MM). Only effective when timing_mode=absolute. Example: "23:00".'
            ),
            'event_format' => $stringArray->description(
                'Array of delivery format values. Valid values: physical, online, hybrid. Returns events matching any of the given formats.'
            ),
            'has_event_url' => $schema->boolean()->nullable()->description(
                'Pass true to return only events that have a registration/event URL. Pass false to exclude them.'
            ),
            'has_live_url' => $schema->boolean()->nullable()->description(
                'Pass true to return only events that have a live-stream URL. Pass false to exclude them.'
            ),
            'has_end_time' => $schema->boolean()->nullable()->description(
                'Pass true to return only events with a specified end time. Pass false to return events without one.'
            ),
            'search_include_institutions' => $schema->boolean()->nullable()->description(
                'When false, the keyword search (query) will not expand to match institution names. Default: true.'
            ),
            'search_include_speakers' => $schema->boolean()->nullable()->description(
                'When false, the keyword search (query) will not expand to match speaker names or key-person names. Default: true.'
            ),
            'search_include_references' => $schema->boolean()->nullable()->description(
                'When false, the keyword search (query) will not expand to match reference (book/kitab) titles or authors. Default: true.'
            ),
            'reference_author_search' => $stringArray->description(
                'Array of reference author keywords/names. Returns events linked to references whose author matches any provided value.'
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $arguments): array
    {
        $filters = Arr::only($arguments, self::FILTER_KEYS);

        foreach (self::ARRAY_FILTER_KEYS as $key) {
            $filters[$key] = $this->normalizeStringArray($filters[$key] ?? []);
        }

        $filters['person_in_charge_search'] = $this->normalizeOptionalString($filters['person_in_charge_search'] ?? null);
        $filters['starts_time_from'] = $this->normalizeOptionalString($filters['starts_time_from'] ?? null);
        $filters['starts_time_until'] = $this->normalizeOptionalString($filters['starts_time_until'] ?? null);

        // Scope toggles: default to true when absent; must survive array_filter as they can be false.
        $includeInstitutions = isset($filters['search_include_institutions'])
            ? (bool) $filters['search_include_institutions']
            : true;
        $includeSpeakers = isset($filters['search_include_speakers'])
            ? (bool) $filters['search_include_speakers']
            : true;
        $includeReferences = isset($filters['search_include_references'])
            ? (bool) $filters['search_include_references']
            : true;

        unset($filters['search_include_institutions'], $filters['search_include_speakers'], $filters['search_include_references']);

        if (($filters['time_scope'] ?? 'upcoming') === 'upcoming') {
            $filters['time_scope'] = null;
        }

        if (($filters['timing_mode'] ?? null) !== TimingMode::Absolute->value) {
            $filters['starts_time_from'] = null;
            $filters['starts_time_until'] = null;
        }

        if (($filters['timing_mode'] ?? null) === TimingMode::Absolute->value) {
            $filters['prayer_time'] = null;
        }

        $filtered = array_filter($filters, function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });

        // Boolean scope toggles must be preserved even when false.
        $filtered['search_include_institutions'] = $includeInstitutions;
        $filtered['search_include_speakers'] = $includeSpeakers;
        $filtered['search_include_references'] = $includeReferences;

        return $filtered;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(strval(...), $values), static fn (string $item): bool => $item !== ''));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeOptionalFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  callable(): LengthAwarePaginator<int, Event>  $callback
     * @return LengthAwarePaginator<int, Event>
     */
    private function withCurrentPage(int $page, callable $callback): LengthAwarePaginator
    {
        $requestPageResolver = static fn (string $pageName = 'page'): int => max(1, (int) request()->input($pageName, 1));

        Paginator::currentPageResolver(static fn (): int => $page);

        try {
            return $callback();
        } finally {
            Paginator::currentPageResolver($requestPageResolver);
        }
    }
}
