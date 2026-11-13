<?php

declare(strict_types=1);

namespace App\Support\Discovery;

use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Shared\Discovery\SearchFilterCatalog;
use App\Shared\Discovery\SearchFilterKeys;
use App\Shared\Discovery\SearchFilterRules;
use App\Shared\Taxonomy\EventTaxonomyCode;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final readonly class EventSearchFilterRules implements SearchFilterRules
{
    public function __construct(private SearchFilterCatalog $catalog) {}

    public function rules(mixed $filters): array
    {
        $categoryIds = is_array($filters) ? ($filters['event_category_ids'] ?? []) : [];

        return [
            'filters' => 'nullable|array:'.implode(',', SearchFilterKeys::all()),
            'filters.country_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')],
            'filters.state_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::stateClass(), 'id')],
            'filters.city_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::cityClass(), 'id')],
            'filters.area_assignments' => 'nullable|array',
            'filters.area_assignments.*' => ['nullable', 'uuid', Rule::exists(ModelResolver::areaClass(), 'id')],
            'filters.institution_id' => 'nullable|uuid|exists:institutions,id',
            'filters.venue_id' => 'nullable|uuid|exists:venues,id',
            'filters.language_codes' => 'nullable|array',
            'filters.language_codes.*' => 'string|max:12',
            'filters.event_category_ids' => 'nullable|array',
            'filters.event_category_ids.*' => ['uuid', Rule::in($this->catalog->validCategoryIds((array) $categoryIds))],
            'filters.event_format' => 'nullable|array',
            'filters.event_format.*' => ['string', Rule::in($this->catalog->allowedValues('event_format'))],
            'filters.gender' => ['nullable', 'string', Rule::in($this->catalog->allowedValues('gender'))],
            'filters.age_group' => 'nullable|array',
            'filters.age_group.*' => ['string', Rule::in($this->catalog->allowedValues('age_group'))],
            'filters.starts_after' => 'nullable|date_format:Y-m-d',
            'filters.starts_before' => 'nullable|date_format:Y-m-d',
            'filters.starts_on_local_date' => 'nullable|date_format:Y-m-d',
            'filters.time_scope' => ['nullable', 'string', Rule::in($this->catalog->allowedValues('time_scope'))],
            'filters.prayer_time' => ['nullable', $this->validPrayerTimeFilterRule()],
            'filters.timing_mode' => ['nullable', 'string', Rule::in($this->catalog->allowedValues('timing_mode'))],
            'filters.starts_time_from' => ['nullable', 'string', 'max:32', $this->validTimeFilterRule()],
            'filters.starts_time_until' => ['nullable', 'string', 'max:32', $this->validTimeFilterRule()],
            'filters.children_allowed' => ['nullable', $this->validBooleanFilterRule()],
            'filters.is_muslim_only' => ['nullable', $this->validBooleanFilterRule()],
            'filters.has_event_url' => ['nullable', $this->validBooleanFilterRule()],
            'filters.has_live_url' => ['nullable', $this->validBooleanFilterRule()],
            'filters.has_end_time' => ['nullable', $this->validBooleanFilterRule()],
            'filters.person_ids' => 'nullable|array',
            'filters.person_ids.*' => 'uuid|exists:persons,id',
            'filters.key_person_roles' => 'nullable|array',
            'filters.key_person_roles.*' => ['string', Rule::in($this->catalog->allowedValues('key_person_roles'))],
            'filters.person_in_charge_ids' => 'nullable|array',
            'filters.person_in_charge_ids.*' => 'uuid|exists:persons,id',
            'filters.person_in_charge_search' => 'nullable|string|max:255',
            'filters.person_name_search' => 'nullable|string|max:255',
            'filters.moderator_ids' => 'nullable|array',
            'filters.moderator_ids.*' => 'uuid|exists:persons,id',
            'filters.imam_ids' => 'nullable|array',
            'filters.imam_ids.*' => 'uuid|exists:persons,id',
            'filters.khatib_ids' => 'nullable|array',
            'filters.khatib_ids.*' => 'uuid|exists:persons,id',
            'filters.bilal_ids' => 'nullable|array',
            'filters.bilal_ids.*' => 'uuid|exists:persons,id',
            'filters.discipline_tag_ids' => 'nullable|array',
            'filters.discipline_tag_ids.*' => ['uuid', $this->taxonomyTermExistsRule(EventTaxonomyCode::Discipline)],
            'filters.domain_tag_ids' => 'nullable|array',
            'filters.domain_tag_ids.*' => ['uuid', $this->taxonomyTermExistsRule(EventTaxonomyCode::Domain)],
            'filters.source_tag_ids' => 'nullable|array',
            'filters.source_tag_ids.*' => ['uuid', $this->taxonomyTermExistsRule(EventTaxonomyCode::Source)],
            'filters.issue_tag_ids' => 'nullable|array',
            'filters.issue_tag_ids.*' => ['uuid', $this->taxonomyTermExistsRule(EventTaxonomyCode::Issue)],
            'filters.reference_ids' => 'nullable|array',
            'filters.reference_ids.*' => 'uuid|exists:references,id',
            'filters.reference_author_search' => ['nullable', $this->validStringOrStringListRule()],
        ];
    }

    private function taxonomyTermExistsRule(EventTaxonomyCode $taxonomy): Exists
    {
        return Rule::exists(EventTerm::class, 'id')
            ->where(static function (Builder $query) use ($taxonomy): void {
                $query->whereIn(
                    (new EventTerm)->qualifyColumn('event_taxonomy_id'),
                    EventTaxonomy::query()->select('id')->where('code', $taxonomy->value),
                );
            });
    }

    private function validTimeFilterRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            try {
                now()->setTimeFromTimeString(trim($value));
            } catch (\Throwable) {
                $fail('The '.$attribute.' must be a valid time.');
            }
        };
    }

    private function validPrayerTimeFilterRule(): \Closure
    {
        $allowedValues = $this->catalog->allowedValues('prayer_time');

        return static function (string $attribute, mixed $value, \Closure $fail) use ($allowedValues): void {
            if ($value === null || $value === '') {
                return;
            }

            $values = is_array($value) ? $value : [$value];

            if (! array_is_list($values)) {
                $fail('The selected '.$attribute.' is invalid.');

                return;
            }

            foreach ($values as $prayerTime) {
                if (! is_string($prayerTime) || ! in_array($prayerTime, $allowedValues, true)) {
                    $fail('The selected '.$attribute.' is invalid.');

                    return;
                }
            }
        };
    }

    private function validBooleanFilterRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (is_bool($value) || in_array($value, [1, '1', 'true', 'on', 'yes', 0, '0', 'false', 'off', 'no'], true)) {
                return;
            }

            $fail('The '.$attribute.' field must be boolean-like.');
        };
    }

    private function validStringOrStringListRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (is_string($value)) {
                if (mb_strlen($value) > 255) {
                    $fail('The '.$attribute.' field must not exceed 255 characters.');
                }

                return;
            }

            if (! is_array($value) || ! array_is_list($value)) {
                $fail('The '.$attribute.' field must be a string or a list of strings.');

                return;
            }

            foreach ($value as $item) {
                if (! is_string($item)) {
                    $fail('The '.$attribute.' field must be a string or a list of strings.');

                    return;
                }

                if (mb_strlen($item) > 255) {
                    $fail('The '.$attribute.' field must not exceed 255 characters per value.');

                    return;
                }
            }
        };
    }
}
