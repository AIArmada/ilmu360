<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TimingMode;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;

final class EventDiscoveryCriteriaFactory
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function fromSearch(
        ?string $text,
        array $filters,
        int $perPage,
        string $sort,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $radiusKm = null,
    ): EventDiscoveryCriteria {
        $normalizedFilters = $this->normalizeFilters($filters);
        $normalizedText = $this->normalizeText($text);
        $startsAfter = $this->parseDate($normalizedFilters['starts_after'] ?? null, false);
        $startsBefore = $this->parseDate($normalizedFilters['starts_before'] ?? null, true);

        return new EventDiscoveryCriteria(
            text: $normalizedText,
            fuzzyEligible: $normalizedText !== null && mb_strlen($normalizedText) >= 3,
            startsAfterUtc: $startsAfter,
            startsBeforeUtc: $startsBefore,
            sort: $sort,
            countryId: $this->uuid($normalizedFilters['country_id'] ?? null),
            stateId: $this->uuid($normalizedFilters['state_id'] ?? null),
            cityId: $this->uuid($normalizedFilters['city_id'] ?? null),
            areaAssignments: $this->normalizeAssignments($normalizedFilters['area_assignments'] ?? []),
            eventFilters: $this->select($normalizedFilters, [
                'status', 'visibility', 'event_format', 'event_category_ids', 'gender', 'age_group',
                'children_allowed', 'time_scope', 'timing_mode', 'prayer_time', 'starts_after',
                'starts_before', 'starts_on_local_date', 'starts_time_from', 'starts_time_until',
                'has_end_time', 'has_event_url', 'has_live_url', 'is_muslim_only',
                'search_include_institutions', 'search_include_persons', 'search_include_references',
            ]),
            relationFilters: $this->select($normalizedFilters, [
                'country_id', 'state_id', 'city_id', 'area_assignments',
                'institution_id', 'venue_id', 'person_ids', 'reference_ids', 'language_codes',
                'person_in_charge_ids', 'person_in_charge_search', 'domain_tag_ids', 'discipline_tag_ids',
                'source_tag_ids', 'issue_tag_ids', 'event_category_ids',
            ]),
            latitude: $latitude,
            longitude: $longitude,
            radiusKm: $radiusKm,
            page: Paginator::resolveCurrentPage(),
            perPage: $perPage,
            requiresDatabaseFiltering: $this->requiresDatabaseFiltering($normalizedFilters),
            filters: $normalizedFilters,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        foreach ($filters as $key => $value) {
            if ($key === 'area_assignments') {
                $normalized[$key] = is_array($value) ? $value : [];
            } else {
                $normalized[$key] = $this->normalizeValue($value);
            }
        }

        foreach (['country_id', 'state_id', 'city_id', 'institution_id', 'venue_id'] as $key) {
            if (array_key_exists($key, $normalized)) {
                $normalized[$key] = $this->uuid($normalized[$key]);
            }
        }

        foreach (['person_ids', 'reference_ids', 'person_in_charge_ids', 'domain_tag_ids', 'discipline_tag_ids', 'source_tag_ids', 'issue_tag_ids', 'event_category_ids'] as $key) {
            if (array_key_exists($key, $normalized)) {
                $normalized[$key] = $this->uuidList($normalized[$key]);
            }
        }

        return array_filter($normalized, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array<string, string> */
    private function normalizeAssignments(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $assignments = [];

        foreach ($value as $role => $areaId) {
            if (is_string($role) && is_string($areaId) && Str::isUuid($areaId)) {
                $assignments[$role] = $areaId;
            }
        }

        return $assignments;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map($this->normalizeValue(...), $value), static fn (mixed $item): bool => $item !== null));
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    private function normalizeText(?string $value): ?string
    {
        $normalized = $this->normalizeValue($value);

        return is_string($normalized) ? $normalized : null;
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function uuidList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter($values, fn (mixed $item): bool => $this->uuid($item) !== null));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function select(array $values, array $keys): array
    {
        return array_intersect_key($values, array_flip($keys));
    }

    private function parseDate(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        $date = UserDateTimeFormatter::parseUserDateToUtc($value, $endOfDay);

        return $date instanceof CarbonImmutable ? $date : ($date?->toImmutable());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function requiresDatabaseFiltering(array $filters): bool
    {
        return $this->normalizeText($filters['prayer_time'] ?? null) !== null
            || $this->arrayValue($filters['language_codes'] ?? null) !== []
            || $this->arrayValue($filters['reference_author_search'] ?? null) !== []
            || $this->normalizeText($filters['person_in_charge_search'] ?? null) !== null
            || in_array($filters['timing_mode'] ?? null, [TimingMode::Absolute->value, TimingMode::PrayerRelative->value], true)
            || $this->normalizeText($filters['starts_time_from'] ?? null) !== null
            || $this->normalizeText($filters['starts_time_until'] ?? null) !== null
            || filled($filters['venue_id'] ?? null)
            || $this->boolean($filters['is_muslim_only'] ?? null) !== null
            || $this->boolean($filters['has_event_url'] ?? null) !== null
            || $this->boolean($filters['has_live_url'] ?? null) !== null
            || $this->boolean($filters['has_end_time'] ?? null) !== null;
    }

    /**
     * @return list<mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : ($value === null ? [] : [$value]);
    }

    private function boolean(mixed $value): ?bool
    {
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
}
