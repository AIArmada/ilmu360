<?php

namespace App\Support\SavedSearches;

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\TimingMode;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class SavedSearchFilterNormalizer
{
    /**
     * @return array<string, mixed>|null
     */
    public function normalizeForStorage(mixed $filters): ?array
    {
        if (! is_array($filters) || $filters === []) {
            return null;
        }

        $normalizedFilters = Arr::only($filters, $this->allowedFilterKeys());

        foreach (['country_id', 'state_id', 'district_id', 'subdistrict_id'] as $integerFilter) {
            $this->normalizePositiveIntegerScalarFilter($normalizedFilters, $integerFilter);
        }

        foreach (['institution_id', 'venue_id'] as $uuidFilter) {
            $this->normalizeUuidScalarFilter($normalizedFilters, $uuidFilter);
        }

        foreach ([
            'speaker_ids',
            'person_in_charge_ids',
            'moderator_ids',
            'imam_ids',
            'khatib_ids',
            'bilal_ids',
            'domain_tag_ids',
            'topic_ids',
            'source_tag_ids',
            'issue_tag_ids',
            'reference_ids',
        ] as $uuidArrayFilter) {
            $this->normalizeUuidArrayFilter($normalizedFilters, $uuidArrayFilter);
        }

        $this->normalizeLanguageCodes($normalizedFilters);
        $this->normalizeEnumArrayFilter($normalizedFilters, 'event_type', EventType::class);
        $this->normalizeEnumArrayFilter($normalizedFilters, 'event_format', EventFormat::class);
        $this->normalizeEnumArrayFilter($normalizedFilters, 'age_group', EventAgeGroup::class);
        $this->normalizeKeyPersonRoles($normalizedFilters);
        $this->normalizeEnumScalarFilter($normalizedFilters, 'gender', EventGenderRestriction::class);
        $this->normalizeEnumScalarFilter($normalizedFilters, 'prayer_time', EventPrayerTime::class);
        $this->normalizeEnumScalarFilter($normalizedFilters, 'timing_mode', TimingMode::class);
        $this->normalizeTrimmedStringFilter($normalizedFilters, 'person_in_charge_search');
        $this->normalizeDateScalarFilter($normalizedFilters, 'starts_after');
        $this->normalizeDateScalarFilter($normalizedFilters, 'starts_before');
        $this->normalizeDateScalarFilter($normalizedFilters, 'starts_on_local_date');
        $this->normalizeTimeScope($normalizedFilters);
        $this->normalizeTimeScalarFilter($normalizedFilters, 'starts_time_from');
        $this->normalizeTimeScalarFilter($normalizedFilters, 'starts_time_until');

        foreach (['children_allowed', 'is_muslim_only', 'has_event_url', 'has_live_url', 'has_end_time'] as $booleanFilter) {
            $this->normalizeBooleanScalarFilter($normalizedFilters, $booleanFilter);
        }

        /** @var array<string, mixed> $normalizedFilters */
        return $normalizedFilters === [] ? null : $normalizedFilters;
    }

    /**
     * @return list<string>
     */
    public function allowedFilterKeys(): array
    {
        return [
            'country_id',
            'state_id',
            'district_id',
            'subdistrict_id',
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
            'domain_tag_ids',
            'topic_ids',
            'source_tag_ids',
            'issue_tag_ids',
            'reference_ids',
            'language_codes',
            'event_type',
            'event_format',
            'gender',
            'starts_after',
            'starts_before',
            'starts_on_local_date',
            'time_scope',
            'prayer_time',
            'timing_mode',
            'starts_time_from',
            'starts_time_until',
            'children_allowed',
            'is_muslim_only',
            'has_event_url',
            'has_live_url',
            'has_end_time',
            'age_group',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeLanguageCodes(array &$filters): void
    {
        if (! array_key_exists('language_codes', $filters)) {
            return;
        }

        $filters['language_codes'] = array_values(array_filter(
            array_map(static function (mixed $languageCode): ?string {
                if (! is_scalar($languageCode)) {
                    return null;
                }

                $normalizedLanguageCode = mb_strtolower(trim((string) $languageCode));

                return $normalizedLanguageCode !== '' ? $normalizedLanguageCode : null;
            }, (array) $filters['language_codes']),
            static fn (?string $languageCode): bool => $languageCode !== null,
        ));

        if ($filters['language_codes'] === []) {
            unset($filters['language_codes']);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  class-string<BackedEnum>  $enumClass
     */
    private function normalizeEnumArrayFilter(array &$filters, string $key, string $enumClass): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $values = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->enumBackingValue($value, $enumClass),
            is_array($filters[$key]) ? $filters[$key] : [$filters[$key]],
        ))));

        if ($values === []) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = $values;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizePositiveIntegerScalarFilter(array &$filters, string $key): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        if (is_int($filters[$key])) {
            $value = $filters[$key];
        } elseif (is_scalar($filters[$key])) {
            $normalized = trim((string) $filters[$key]);
            $value = ctype_digit($normalized) ? (int) $normalized : null;
        } else {
            $value = null;
        }

        if (! is_int($value) || $value <= 0) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeUuidScalarFilter(array &$filters, string $key): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $value = $this->trimmedScalarString($filters[$key]);

        if (! is_string($value) || ! Str::isUuid($value)) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeUuidArrayFilter(array &$filters, string $key): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $values = array_values(array_unique(array_filter(array_map(function (mixed $value): ?string {
            $normalized = $this->trimmedScalarString($value);

            return is_string($normalized) && Str::isUuid($normalized) ? $normalized : null;
        }, is_array($filters[$key]) ? $filters[$key] : [$filters[$key]]))));

        if ($values === []) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = $values;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeKeyPersonRoles(array &$filters): void
    {
        if (! array_key_exists('key_person_roles', $filters)) {
            return;
        }

        $allowedRoles = array_keys(EventKeyPersonRole::nonSpeakerOptions());
        $values = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->enumBackingValue($value, EventKeyPersonRole::class),
            is_array($filters['key_person_roles']) ? $filters['key_person_roles'] : [$filters['key_person_roles']],
        ), static fn (?string $value): bool => is_string($value) && in_array($value, $allowedRoles, true))));

        if ($values === []) {
            unset($filters['key_person_roles']);

            return;
        }

        $filters['key_person_roles'] = $values;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeTrimmedStringFilter(array &$filters, string $key): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $value = $this->trimmedScalarString($filters[$key]);

        if ($value === null) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  class-string<BackedEnum>  $enumClass
     */
    private function normalizeEnumScalarFilter(array &$filters, string $key, string $enumClass): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $value = $this->enumBackingValue($filters[$key], $enumClass);

        if ($value === null) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeDateScalarFilter(array &$filters, string $key): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $value = $this->trimmedScalarString($filters[$key]);

        if ($value === null || ! $this->isCanonicalDate($value)) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeTimeScope(array &$filters): void
    {
        if (! array_key_exists('time_scope', $filters)) {
            return;
        }

        $value = is_scalar($filters['time_scope'])
            ? trim((string) $filters['time_scope'])
            : '';

        if (! in_array($value, ['upcoming', 'past', 'all'], true)) {
            unset($filters['time_scope']);

            return;
        }

        $filters['time_scope'] = $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeTimeScalarFilter(array &$filters, string $key): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $value = $this->trimmedScalarString($filters[$key]);

        if ($value === null) {
            unset($filters[$key]);

            return;
        }

        try {
            $filters[$key] = now()->setTimeFromTimeString($value)->format('H:i');
        } catch (\Throwable) {
            unset($filters[$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalizeBooleanScalarFilter(array &$filters, string $key): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $value = $this->booleanValue($filters[$key]);

        if (! is_bool($value)) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = $value;
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     */
    private function enumBackingValue(mixed $value, string $enumClass): ?string
    {
        $raw = $this->trimmedScalarString($value);

        if ($raw === null) {
            return null;
        }

        $enum = $enumClass::tryFrom($raw);

        return $enum instanceof BackedEnum ? (string) $enum->value : null;
    }

    private function trimmedScalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function isCanonicalDate(string $value): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = $this->trimmedScalarString($value);

        if ($normalized === null) {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return null;
    }
}
