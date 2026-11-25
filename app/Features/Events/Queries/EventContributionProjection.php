<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\Events\Api\ContributionProjection;
use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventGenderRestriction;
use App\Features\Events\Api\Enums\EventKeyPersonRole;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\EventTaxonomyCode;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Platform\Models\MorphModelResolver;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use RuntimeException;

final readonly class EventContributionProjection implements ContributionProjection
{
    use \App\Shared\Contributions\Support\BuildsContributionDescriptors;
    use \App\Shared\Contributions\Support\BuildsContributionSubjectState;

    public function __construct(
        private EventAuthoring $authoring,
    ) {}

    /** @return array<string, mixed> */
    public function contractFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        return [
            'accepts_partial_updates' => true,
            'fields' => [
                $this->field('title', 'string', maxLength: 255),
                $this->field('description', 'rich_text'),
                $this->field('event_date', 'date'),
                $this->field('prayer_time', 'string', allowedValues: $this->enumValues(EventPrayerTime::class)),
                $this->field('custom_time', 'time'),
                $this->field('end_time', 'time'),
                $this->field('timezone', 'timezone'),
                $this->field('event_category_ids', 'array<uuid>', allowedValues: array_keys(app(EventCategoryCatalog::class)->options())),
                $this->field('gender', 'string', allowedValues: $this->enumValues(EventGenderRestriction::class)),
                $this->field('age_group', 'array<string>', allowedValues: $this->enumValues(EventAgeGroup::class)),
                $this->field('children_allowed', 'boolean'),
                $this->field('is_muslim_only', 'boolean'),
                $this->field('event_format', 'string', allowedValues: $this->enumValues(EventFormat::class)),
                $this->field('visibility', 'string', allowedValues: $this->enumValues(EventVisibility::class)),
                $this->field('event_url', 'url'),
                $this->field('live_url', 'url'),
                $this->field('recording_url', 'url'),
                $this->field('primary_organizer_id', 'uuid'),
                $this->field('location_same_as_institution', 'boolean'),
                $this->field('location_type', 'string', allowedValues: ['institution', 'venue']),
                $this->field('location_institution_id', 'uuid', catalog: route('api.client.catalogs.submit-institutions')),
                $this->field('location_venue_id', 'uuid', catalog: route('api.client.catalogs.venues')),
                $this->field('space_ids', 'array<uuid>', catalog: route('api.client.catalogs.spaces')),
                $this->field('language_ids', 'array<string>', catalog: route('api.client.catalogs.languages')),
                $this->field('domain_tags', 'array<string>', catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Domain->value])),
                $this->field('discipline_tags', 'array<string>', catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Discipline->value])),
                $this->field('source_tags', 'array<string>', catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Source->value])),
                $this->field('issue_tags', 'array<string>', catalog: route('api.client.catalogs.taxonomy-terms', ['type' => EventTaxonomyCode::Issue->value])),
                $this->field('reference_ids', 'array<string>', catalog: route('api.client.catalogs.references')),
                $this->field('series_ids', 'array<string>'),
                $this->field('person_ids', 'array<string>', catalog: route('api.client.catalogs.submit-persons')),
                $this->field('other_key_people', 'array<object>'),
            ],
            'conditional_rules' => [
                ['field' => 'custom_time', 'required_when' => ['prayer_time' => [EventPrayerTime::LainWaktu->value]]],
                ['field' => 'location_type', 'required_when' => ['location_same_as_institution' => [false]]],
                ['field' => 'location_institution_id', 'required_when' => ['location_type' => ['institution']]],
                ['field' => 'location_venue_id', 'required_when' => ['location_type' => ['venue']]],
            ],
            'direct_edit_media_fields' => ['cover', 'poster', 'gallery'],
        ];
    }

    /** @return array<string, mixed> */
    public function stateFor(string $subjectId): array
    {
        return $this->eventState($this->resolve($subjectId));
    }

    /** @return array<string, mixed> */
    public function updateRulesFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable'],
            'event_date' => ['sometimes', 'date'],
            'prayer_time' => ['sometimes', Rule::in($this->enumValues(EventPrayerTime::class))],
            'custom_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['sometimes', 'timezone'],
            'event_category_ids' => ['sometimes', 'array'],
            'event_category_ids.*' => ['uuid', Rule::in(array_keys(app(EventCategoryCatalog::class)->options()))],
            'gender' => ['sometimes', Rule::in($this->enumValues(EventGenderRestriction::class))],
            'age_group' => ['sometimes', 'array'],
            'age_group.*' => ['string', Rule::in($this->enumValues(EventAgeGroup::class))],
            'children_allowed' => ['nullable', 'boolean'],
            'is_muslim_only' => ['nullable', 'boolean'],
            'event_format' => ['sometimes', Rule::in($this->enumValues(EventFormat::class))],
            'visibility' => ['sometimes', Rule::in($this->enumValues(EventVisibility::class))],
            'event_url' => ['nullable', 'url', 'max:255'],
            'live_url' => ['nullable', 'url', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:255'],
            'primary_organizer_id' => ['sometimes', 'nullable', 'uuid'],
            'location_same_as_institution' => ['sometimes', 'boolean'],
            'location_type' => ['sometimes', 'string', Rule::in(['institution', 'venue'])],
            'location_institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
            'location_venue_id' => ['nullable', 'uuid', 'exists:venues,id'],
            'space_ids' => ['sometimes', 'array'],
            'space_ids.*' => ['uuid', 'exists:venue_spaces,id'],
            'language_ids' => ['sometimes', 'array'],
            'language_ids.*' => ['string', 'exists:languages,id'],
            'domain_tags' => ['sometimes', 'array'],
            'domain_tags.*' => ['string', 'max:120'],
            'discipline_tags' => ['sometimes', 'array'],
            'discipline_tags.*' => ['string', 'max:120'],
            'source_tags' => ['sometimes', 'array'],
            'source_tags.*' => ['string', 'max:120'],
            'issue_tags' => ['sometimes', 'array'],
            'issue_tags.*' => ['string', 'max:120'],
            'reference_ids' => ['sometimes', 'array'],
            'reference_ids.*' => ['uuid', 'exists:references,id'],
            'series_ids' => ['sometimes', 'array'],
            'series_ids.*' => ['uuid', Rule::exists(Series::class, 'id')],
            'person_ids' => ['sometimes', 'array'],
            'person_ids.*' => ['uuid', 'exists:persons,id'],
            'other_key_people' => ['sometimes', 'array'],
            'other_key_people.*.role_code' => ['required_with:other_key_people.*.display_name,other_key_people.*.involveable_id', Rule::in($this->enumValues(EventKeyPersonRole::class))],
            'other_key_people.*.involveable_type' => ['nullable', 'string', 'max:255'],
            'other_key_people.*.involveable_id' => ['nullable', 'uuid', 'exists:persons,id', 'required_without:other_key_people.*.display_name'],
            'other_key_people.*.display_name' => ['nullable', 'string', 'max:255', 'required_without:other_key_people.*.involveable_id'],
            'other_key_people.*.visibility' => ['nullable', Rule::in(['public', 'private'])],
            'other_key_people.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function resolve(string $subjectId): Event
    {
        $modelClass = MorphModelResolver::resolve('event');
        $model = $modelClass::query()->findOrFail($subjectId);

        if (! $model instanceof Event) {
            throw new RuntimeException('Expected the resolved contribution subject to be a event.');
        }

        return $model;
    }

    /** @return array<string, mixed> */
    private function eventState(Event $event): array
    {
        $event->loadMissing([
            'references',
            'series',
            'classifications',
            'keyPeople.person',
            'languages:id,event_id,event_occurrence_id,event_session_id,language_code',
            'primaryOccurrence',
            'primaryOrganizerInvolvement',
            'locations',
            'audiences',
            'audienceProfiles',
            'timeExpressions',
            'links',
        ]);

        $tags = $event->classifications->groupBy('taxonomy_code');

        return [
            'title' => $event->title,
            'description' => $event->description,
            'starts_at' => $event->starts_at?->toDateTimeString(),
            'ends_at' => $event->ends_at?->toDateTimeString(),
            'timezone' => $event->timezone,
            'timing_mode' => $event->timing_mode instanceof BackedEnum
                ? $event->timing_mode->value
                : (is_string($event->timing_mode) && $event->timing_mode !== '' ? $event->timing_mode : null),
            'prayer_reference' => $event->prayer_reference instanceof BackedEnum
                ? $event->prayer_reference->value
                : (is_string($event->prayer_reference) && $event->prayer_reference !== '' ? $event->prayer_reference : null),
            'prayer_offset' => $event->prayer_offset instanceof BackedEnum
                ? $event->prayer_offset->value
                : (is_string($event->prayer_offset) && $event->prayer_offset !== '' ? $event->prayer_offset : null),
            'prayer_display_text' => $event->prayer_display_text,
            // The persisted prayer day restores the form's event_date for
            // overnight starts (whose local day differs from the prayer
            // day) before helper-field derivation in UI/API/originals.
            'prayer_date' => $this->eventPrayerDate($event),
            'event_category_ids' => $event->classifications->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE)->pluck('event_term_id')->values()->all(),
            'gender' => $event->gender instanceof BackedEnum ? $event->gender->value : (string) $event->gender,
            'age_group' => $this->enumCollectionValues($event->age_group),
            'children_allowed' => (bool) $event->children_allowed,
            'is_muslim_only' => (bool) $event->is_muslim_only,
            'event_format' => $event->delivery_mode instanceof BackedEnum ? $event->delivery_mode->value : (string) $event->delivery_mode,
            'visibility' => $event->visibility instanceof BackedEnum ? $event->visibility->value : (string) $event->visibility,
            'event_url' => $event->event_url,
            'live_url' => $event->live_url,
            'recording_url' => $event->recording_url,
            'primary_organizer_id' => $event->primaryOrganizerInvolvement?->involveable_id,
            'institution_id' => $event->institution_id,
            'venue_id' => $event->default_venue_id,
            'space_ids' => $event->locations->sortBy('sort_order')->pluck('venue_space_id')
                ->filter()->map(strval(...))->values()->all(),
            'language_ids' => $event->languages->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'domain_tags' => $tags->get(EventTaxonomyCode::Domain->value, collect())->pluck('event_term_id')->values()->all(),
            'discipline_tags' => $tags->get(EventTaxonomyCode::Discipline->value, collect())->pluck('event_term_id')->values()->all(),
            'source_tags' => $tags->get(EventTaxonomyCode::Source->value, collect())->pluck('event_term_id')->values()->all(),
            'issue_tags' => $tags->get(EventTaxonomyCode::Issue->value, collect())->pluck('event_term_id')->values()->all(),
            'reference_ids' => $event->references->pluck('id')->values()->all(),
            'series_ids' => $event->series->pluck('id')->values()->all(),
            'person_ids' => $event->keyPeople
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->pluck('involveable_id')
                ->filter(fn (mixed $personId): bool => is_string($personId) && $personId !== '')
                ->values()
                ->all(),
            'other_key_people' => $event->keyPeople
                ->reject(fn ($keyPerson): bool => $keyPerson->role_code === EventKeyPersonRole::Speaker->value)
                ->map(fn ($keyPerson): array => [
                    'role_code' => (string) $keyPerson->role_code,
                    'involveable_type' => $keyPerson->involveable_type,
                    'involveable_id' => $keyPerson->involveable_id,
                    'display_name' => $keyPerson->display_name,
                    'visibility' => $keyPerson->visibility ?? 'public',
                    'notes' => $keyPerson->notes,
                ])
                ->values()
                ->all(),
        ];
    }

    private function eventPrayerDate(Event $event): ?string
    {
        $meta = $event->timeExpressions->firstWhere('anchor_type', 'prayer')?->metadata['prayer'] ?? null;

        return is_array($meta)
            ? $this->authoring->normalizePrayerDateString($meta['prayer_date'] ?? null)
            : null;
    }

    /** @return list<string> */
    private function enumCollectionValues(mixed $collection): array
    {
        if ($collection instanceof Collection) {
            return $collection
                ->map(fn (mixed $value): ?string => $value instanceof BackedEnum ? $value->value : (is_string($value) ? $value : null))
                ->filter()
                ->values()
                ->all();
        }

        if (is_array($collection)) {
            return $this->normalizeStringArray($collection);
        }

        return [];
    }

    /**
     * @param  iterable<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringArray(iterable $values): array
    {
        return collect($values)
            ->map(function (mixed $value): ?string {
                if ($value instanceof BackedEnum) {
                    return trim((string) $value->value) ?: null;
                }

                return is_string($value) && trim($value) !== '' ? trim($value) : null;
            })
            ->filter()
            ->values()
            ->all();
    }
}
