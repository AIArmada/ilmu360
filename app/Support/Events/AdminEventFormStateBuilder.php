<?php

namespace App\Support\Events;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Enums\ScheduleKind;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Support\Submission\SubmissionTimingPolicy;
use BackedEnum;
use Illuminate\Support\Collection;

final readonly class AdminEventFormStateBuilder
{
    public function __construct(
        private SubmissionTimingPolicy $timing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function defaultsForCreate(): array
    {
        return [
            'status' => 'draft',
            'timezone' => 'Asia/Kuala_Lumpur',
            'schedule_kind' => ScheduleKind::Single->value,
            'prayer_time' => EventPrayerTime::LainWaktu->value,
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'event_category_ids' => array_slice(array_keys(app(EventCategoryCatalog::class)->options()), 0, 1),
            'children_allowed' => false,
            'is_muslim_only' => false,
            'references' => [],
            'series' => [],
            'languages' => [],
            'domain_tags' => [],
            'discipline_tags' => [],
            'source_tags' => [],
            'issue_tags' => [],
            'primary_organizer_id' => null,
            'submission_country_id' => null,
            'persons' => [],
            'other_key_people' => [],
            'registration_required' => false,
            'registration_mode' => RegistrationMode::None->value,
            'is_featured' => false,
            'clear_cover' => false,
            'clear_poster' => false,
            'clear_gallery' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formStateForRecord(Event $event): array
    {
        $event->loadMissing([
            'references.parentReference.parentReference',
            'series:id,title',
            'classifications',
            'keyPeople.person',
            // Full accessor columns: the languages attribute override reads
            // occurrence/session scope plus the code off this same instance.
            'languages:id,event_id,event_occurrence_id,event_session_id,language_code',
            'accessPolicy',
            'primaryLocation.venueSpace',
            'primaryOrganizerInvolvement',
            'locations',
            'audiences',
            'audienceProfiles',
            'links',
            'timeExpressions',
        ]);

        $prayerExpression = $event->timeExpressions
            ->first(fn ($expression): bool => $expression->anchor_type === 'prayer'
                && $expression->event_occurrence_id === null
                && $expression->event_session_id === null);

        $prayerDate = $prayerExpression?->metadata['prayer']['prayer_date'] ?? null;
        $prayerCountry = $prayerExpression?->metadata['prayer']['country'] ?? null;

        $timeFields = AdminEventTimeMapper::injectFormTimeFields([
            'starts_at' => $event->starts_at?->toISOString(),
            'ends_at' => $event->ends_at?->toISOString(),
            'timezone' => $event->timezone,
            'prayer_date' => is_string($prayerDate) ? $prayerDate : null,
            'schedule_kind' => $event->schedule_kind instanceof ScheduleKind ? $event->schedule_kind->value : $event->schedule_kind,
            'timing_mode' => $event->timing_mode instanceof BackedEnum ? $event->timing_mode->value : $event->timing_mode,
            'prayer_reference' => $event->prayer_reference instanceof BackedEnum ? $event->prayer_reference->value : $event->prayer_reference,
            'prayer_offset' => $event->prayer_offset instanceof BackedEnum ? $event->prayer_offset->value : $event->prayer_offset,
        ]);

        $groupedTerms = $event->classifications->groupBy('taxonomy_code');

        return array_replace($this->defaultsForCreate(), [
            'status' => (string) $event->status,
            'title' => $event->title,
            'description' => $event->description,
            'event_date' => $timeFields['event_date'] ?? null,
            'prayer_time' => $timeFields['prayer_time'] ?? EventPrayerTime::LainWaktu->value,
            'custom_time' => $timeFields['custom_time'] ?? null,
            'end_time' => $timeFields['end_time'] ?? null,
            'end_date' => $timeFields['end_date'] ?? null,
            'timezone' => $event->timezone,
            'event_category_ids' => $event->classifications
                ->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE)
                ->pluck('event_term_id')->filter()->values()->all(),
            'gender' => $this->normalizeEnumValue($event->gender, EventGenderRestriction::class, EventGenderRestriction::All->value),
            'age_group' => $this->normalizeEnumValues($event->age_group, EventAgeGroup::class),
            'children_allowed' => (bool) $event->children_allowed,
            'is_muslim_only' => (bool) $event->is_muslim_only,
            'event_format' => $this->normalizeEnumValue($event->delivery_mode, EventFormat::class, EventFormat::Physical->value),
            'visibility' => $this->normalizeEnumValue($event->visibility, EventVisibility::class, EventVisibility::Public->value),
            'event_url' => $event->event_url,
            'live_url' => $event->live_url,
            'recording_url' => $event->recording_url,
            'primary_organizer_id' => $event->primaryOrganizerInvolvement?->involveable_id,
            'submission_country_id' => is_string($prayerCountry) ? $this->timing->resolveSubmissionCountryId($prayerCountry) : null,
            'institution_id' => $event->institution_id,
            'venue_id' => $event->default_venue_id,
            'space_ids' => $event->locations
                ->whereNull('event_occurrence_id')
                ->whereNull('event_session_id')
                ->sortBy('sort_order')
                ->pluck('venue_space_id')
                ->filter()
                ->map(strval(...))
                ->values()
                ->all(),
            'languages' => $event->languages->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'domain_tags' => $groupedTerms->get('domain', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'discipline_tags' => $groupedTerms->get('discipline', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'source_tags' => $groupedTerms->get('source', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'issue_tags' => $groupedTerms->get('issue', collect())->pluck('event_term_id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'references' => $event->references->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'series' => $event->series->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'persons' => $event->keyPeople
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->pluck('involveable_id')
                ->filter(fn (mixed $personId): bool => is_string($personId) && $personId !== '')
                ->values()
                ->all(),
            'other_key_people' => $event->keyPeople
                ->where('role_code', '!=', EventKeyPersonRole::Speaker->value)
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
            'registration_required' => (bool) $event->accessPolicy?->registration_required,
            'registration_mode' => $event->resolvedRegistrationMode()->value,
            'is_featured' => (bool) $event->is_featured,
            'clear_cover' => false,
            'clear_poster' => false,
            'clear_gallery' => false,
        ]);
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     */
    private function normalizeEnumValue(mixed $value, string $enumClass, string $default): string
    {
        if ($value instanceof $enumClass) {
            return (string) $value->value;
        }

        if (is_string($value) && $enumClass::tryFrom($value) instanceof BackedEnum) {
            return $value;
        }

        return $default;
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @param  list<string>  $default
     * @return list<string>
     */
    private function normalizeEnumValues(mixed $values, string $enumClass, array $default = []): array
    {
        if ($values instanceof Collection) {
            $items = $values->all();
        } elseif (is_array($values)) {
            $items = $values;
        } elseif ($values instanceof \Traversable) {
            $items = iterator_to_array($values);
        } else {
            return $default;
        }

        $normalized = Collection::make($items)
            ->map(function (mixed $value) use ($enumClass): ?string {
                if ($value instanceof $enumClass) {
                    return (string) $value->value;
                }

                return is_string($value) && $enumClass::tryFrom($value) instanceof BackedEnum
                    ? $value
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : $default;
    }
}
