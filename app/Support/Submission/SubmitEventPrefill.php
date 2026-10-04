<?php

declare(strict_types=1);

namespace App\Support\Submission;

use App\Contracts\EventCategoryCatalog;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Livewire\Pages\SubmitEvent\EventSubmissionFormSchema;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Support\Events\AdminEventTimeMapper;
use BackedEnum;
use Carbon\CarbonInterface;

/**
 * Mount-time prefill defaults for the public event submission form.
 *
 * Pure functions of explicit arguments (source event, actor, country):
 * container/duplicate/scoped overlays for the initial form state.
 */
final class SubmitEventPrefill
{
    /**
     * @return array<string, mixed>
     */
    public static function containerDefaults(Event $event, ?User $actor, ?string $countryId): array
    {
        $event->loadMissing([
            'classifications',
            'references',
            'languages',
            'persons',
            'keyPeople',
            'primaryOccurrence',
            'primaryOrganizerInvolvement',
        ]);

        $eventVisibility = $event->visibility;
        $eventFormat = $event->delivery_mode instanceof EventFormat
            ? $event->delivery_mode->value
            : (is_string($event->delivery_mode) && $event->delivery_mode !== '' ? $event->delivery_mode : EventFormat::Physical->value);
        $eventGender = $event->gender instanceof EventGenderRestriction
            ? $event->gender->value
            : (is_string($event->gender) && $event->gender !== '' ? $event->gender : EventGenderRestriction::All->value);
        $classifications = $event->classifications;

        $defaults = [
            'title' => $event->title,
            'description' => $event->description,
            'event_category_ids' => $classifications
                ->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE)
                ->pluck('event_term_id')
                ->filter()
                ->first(),
            'event_format' => $eventFormat,
            'gender' => $eventGender,
            'age_group' => EventSubmissionFormSchema::normalizeAgeGroupState($event->age_group),
            'children_allowed' => (bool) $event->children_allowed,
            'is_muslim_only' => (bool) $event->is_muslim_only,
            'event_url' => $event->event_url,
            'live_url' => $event->live_url,
            'domain_tags' => $classifications
                ->where('taxonomy_code', EventTaxonomyCode::Domain->value)
                ->pluck('event_term_id')
                ->filter()
                ->first(),
            'discipline_tags' => $classifications
                ->where('taxonomy_code', EventTaxonomyCode::Discipline->value)
                ->pluck('event_term_id')
                ->filter()
                ->values()
                ->all(),
            'source_tags' => $classifications
                ->where('taxonomy_code', EventTaxonomyCode::Source->value)
                ->pluck('event_term_id')
                ->filter()
                ->values()
                ->all(),
            'issue_tags' => $classifications
                ->where('taxonomy_code', EventTaxonomyCode::Issue->value)
                ->pluck('event_term_id')
                ->filter()
                ->values()
                ->all(),
            'references' => $event->references
                ->pluck('id')
                ->filter()
                ->values()
                ->all(),
            'persons' => self::personState($event, $actor, includePrivate: true),
            'other_key_people' => self::otherKeyPeopleState($event, $actor, includePrivate: true),
            'languages' => self::languageState($event),
            'visibility' => $eventVisibility instanceof EventVisibility
                ? $eventVisibility->value
                : (is_string($eventVisibility) && $eventVisibility !== '' ? $eventVisibility : EventVisibility::Public->value),
        ];

        $firstSession = is_array($event->metadata ?? null)
            ? ($event->metadata['advanced_first_session'] ?? [])
            : [];

        if (is_array($firstSession)) {
            $defaults = array_replace($defaults, array_filter([
                'event_date' => $firstSession['event_date'] ?? null,
                'prayer_time' => $firstSession['prayer_time'] ?? null,
                'custom_time' => $firstSession['custom_time'] ?? null,
                'end_time' => $firstSession['end_time'] ?? null,
            ], filled(...)));
        }

        $timing = app(SubmissionTimingPolicy::class);
        $resolvedCountryId = $timing->resolveSubmissionCountryId($countryId);
        $preservedTimezone = $event->primaryOccurrence->timezone ?? $event->timezone;
        $defaults['submission_timezone'] = $timing->defaultSubmissionTimezone($resolvedCountryId, $preservedTimezone);
        $conversionTimezone = $defaults['submission_timezone'] ?? config('app.timezone', 'UTC');

        if (! array_key_exists('event_date', $defaults) && $event->primaryOccurrence?->starts_at instanceof CarbonInterface) {
            $startsAt = $event->primaryOccurrence->starts_at->copy()->timezone($conversionTimezone);
            $defaults['event_date'] = $startsAt->toDateString();
            $defaults['custom_time'] = $startsAt->format('H:i');
            $defaults['prayer_time'] = EventPrayerTime::LainWaktu->value;
        }

        if (! array_key_exists('end_time', $defaults) && $event->primaryOccurrence?->ends_at instanceof CarbonInterface) {
            $defaults['end_time'] = $event->primaryOccurrence->ends_at->copy()->timezone($conversionTimezone)->format('H:i');
        }

        return array_replace($defaults, self::organizerLocationDefaults($event, $actor));
    }

    /**
     * @return array<string, mixed>
     */
    public static function duplicateDefaults(Event $duplicateEvent, ?User $actor, ?string $countryId, bool $includePrivatePeople = false): array
    {
        $timing = app(SubmissionTimingPolicy::class);
        $resolvedCountryId = $timing->resolveSubmissionCountryId($countryId);
        $conversionTimezone = $timing->defaultSubmissionTimezone($resolvedCountryId, $duplicateEvent->timezone)
            ?? config('app.timezone', 'UTC');
        $eventFormat = $duplicateEvent->delivery_mode instanceof EventFormat
            ? $duplicateEvent->delivery_mode->value
            : (is_string($duplicateEvent->delivery_mode) ? $duplicateEvent->delivery_mode : EventFormat::Physical->value);
        $gender = $duplicateEvent->gender instanceof EventGenderRestriction
            ? $duplicateEvent->gender->value
            : (is_string($duplicateEvent->gender) ? $duplicateEvent->gender : EventGenderRestriction::All->value);
        $defaults = [
            'title' => $duplicateEvent->title,
            'description' => self::duplicateDescription($duplicateEvent),
            'event_category_ids' => $duplicateEvent->classifications
                ->where('taxonomy_code', 'event_category')
                ->pluck('event_term_id')
                ->filter()
                ->first(),
            'event_format' => $eventFormat,
            'visibility' => self::duplicateVisibility($duplicateEvent),
            'gender' => $gender,
            'age_group' => EventSubmissionFormSchema::normalizeAgeGroupState($duplicateEvent->age_group),
            'children_allowed' => (bool) $duplicateEvent->children_allowed,
            'is_muslim_only' => (bool) $duplicateEvent->is_muslim_only,
            'event_url' => $duplicateEvent->event_url,
            'live_url' => $duplicateEvent->live_url,
            'domain_tags' => $duplicateEvent->classifications
                ->where('taxonomy_code', EventTaxonomyCode::Domain->value)
                ->pluck('event_term_id')
                ->first(),
            'discipline_tags' => $duplicateEvent->classifications
                ->where('taxonomy_code', EventTaxonomyCode::Discipline->value)
                ->pluck('event_term_id')
                ->values()
                ->all(),
            'source_tags' => $duplicateEvent->classifications
                ->where('taxonomy_code', EventTaxonomyCode::Source->value)
                ->pluck('event_term_id')
                ->values()
                ->all(),
            'issue_tags' => $duplicateEvent->classifications
                ->where('taxonomy_code', EventTaxonomyCode::Issue->value)
                ->pluck('event_term_id')
                ->values()
                ->all(),
            'references' => $duplicateEvent->references->pluck('id')->values()->all(),
            'persons' => self::personState($duplicateEvent, $actor, $includePrivatePeople),
            'other_key_people' => self::otherKeyPeopleState($duplicateEvent, $actor, $includePrivatePeople),
            'submission_timezone' => $timing->defaultSubmissionTimezone($resolvedCountryId, $duplicateEvent->timezone),
        ];

        $languageIds = self::languageState($duplicateEvent);

        if ($languageIds !== []) {
            $defaults['languages'] = $languageIds;
        }

        if ($duplicateEvent->starts_at instanceof CarbonInterface) {
            $startsAt = $duplicateEvent->starts_at->copy()->timezone($conversionTimezone);
            $prayerTime = self::duplicatePrayerTime($duplicateEvent);

            // Offsets can roll the start past midnight; prayer labels
            // re-resolve the original prayer day, never the rolled date.
            $defaults['event_date'] = $prayerTime->isCustomTime()
                ? $startsAt->toDateString()
                : (self::duplicatePrayerDate($duplicateEvent) ?? $startsAt->toDateString());
            $defaults['prayer_time'] = $prayerTime->value;

            if ($prayerTime->isCustomTime()) {
                $defaults['custom_time'] = $startsAt->format('H:i');
            }
        }

        if ($duplicateEvent->ends_at instanceof CarbonInterface) {
            $defaults['end_time'] = $duplicateEvent->ends_at->copy()->timezone($conversionTimezone)->format('H:i');
        }

        return array_replace($defaults, self::organizerLocationDefaults($duplicateEvent, $actor));
    }

    /**
     * @return array<string, mixed>
     */
    public static function scopedDefaults(Institution $institution): array
    {
        return [
            'primary_organizer_kind' => 'institution',
            'primary_organizer_id' => $institution->id,
            'primary_organizer_institution_id' => $institution->id,
            'primary_organizer_person_id' => null,
            'location_same_as_institution' => true,
            'location_type' => 'institution',
            'location_institution_id' => $institution->id,
            'location_venue_id' => null,
            'space_id' => null,
        ];
    }

    public static function duplicateVisibility(Event $event): string
    {
        return $event->visibility instanceof EventVisibility
            ? $event->visibility->value
            : (is_string($event->visibility) && $event->visibility !== '' ? $event->visibility : EventVisibility::Public->value);
    }

    private static function duplicateDescription(Event $duplicateEvent): string
    {
        $description = $duplicateEvent->description;

        if (is_string($description)) {
            return $description;
        }

        $html = data_get($description, 'html');

        if (is_string($html) && $html !== '') {
            return $html;
        }

        $content = data_get($description, 'content');

        if (is_string($content) && $content !== '') {
            return $content;
        }

        return $duplicateEvent->description_text;
    }

    private static function duplicatePrayerDate(Event $duplicateEvent): ?string
    {
        $duplicateEvent->loadMissing('timeExpressions');

        $prayerDate = $duplicateEvent->timeExpressions
            ->first(fn ($expression): bool => $expression->anchor_type === 'prayer'
                && $expression->event_occurrence_id === null
                && $expression->event_session_id === null)
            ?->metadata['prayer']['prayer_date'] ?? null;

        return AdminEventTimeMapper::normalizePrayerDateString($prayerDate);
    }

    private static function duplicatePrayerTime(Event $duplicateEvent): EventPrayerTime
    {
        $prayerDisplayText = $duplicateEvent->prayer_display_text;

        if (is_string($prayerDisplayText) && $prayerDisplayText !== '') {
            foreach (EventPrayerTime::cases() as $prayerTime) {
                if ($prayerTime->getLabel() === $prayerDisplayText) {
                    return $prayerTime;
                }
            }
        }

        $prayerReference = $duplicateEvent->prayer_reference instanceof BackedEnum
            ? (string) $duplicateEvent->prayer_reference->value
            : (is_string($duplicateEvent->prayer_reference) ? $duplicateEvent->prayer_reference : null);
        $prayerOffset = $duplicateEvent->prayer_offset instanceof BackedEnum
            ? (string) $duplicateEvent->prayer_offset->value
            : (is_string($duplicateEvent->prayer_offset) ? $duplicateEvent->prayer_offset : null);

        if (is_string($prayerReference) && $prayerReference !== '') {
            foreach (EventPrayerTime::cases() as $prayerTime) {
                if ($prayerTime->isCustomTime()) {
                    continue;
                }

                if (
                    $prayerTime->toPrayerReference()?->value === $prayerReference
                    && $prayerTime->getDefaultOffset()?->value === $prayerOffset
                ) {
                    return $prayerTime;
                }
            }
        }

        return EventPrayerTime::LainWaktu;
    }

    /**
     * @return list<string>
     */
    private static function languageState(Event $event): array
    {
        return $event->languages
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function personState(Event $duplicateEvent, ?User $actor, bool $includePrivate): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $actor;
        $publicSpeakerIds = $includePrivate ? [] : array_fill_keys($duplicateEvent->keyPeople
            ->where('role_code', EventKeyPersonRole::Speaker->value)
            ->where('involveable_type', 'person')
            ->where('visibility', 'public')
            ->pluck('involveable_id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->all(), true);

        return $duplicateEvent->persons
            ->pluck('id')
            ->filter(fn (mixed $personId): bool => $includePrivate || (is_string($personId) && isset($publicSpeakerIds[$personId])))
            ->map(fn (mixed $personId): ?string => is_string($personId) && $access->canUsePerson($submitter, $personId) ? $personId : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{role_code: string, involveable_type: ?string, involveable_id: ?string, display_name: ?string, visibility: string, notes: ?string}>
     */
    private static function otherKeyPeopleState(Event $duplicateEvent, ?User $actor, bool $includePrivate): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $actor;

        return $duplicateEvent->keyPeople
            ->filter(fn (EventKeyPerson $keyPerson): bool => $includePrivate || $keyPerson->visibility === 'public')
            ->filter(fn (EventKeyPerson $keyPerson): bool => $keyPerson->role_code !== EventKeyPersonRole::Speaker->value)
            ->map(function (EventKeyPerson $keyPerson) use ($access, $submitter): array {
                $personId = is_string($keyPerson->involveable_id) && $access->canUsePerson($submitter, $keyPerson->involveable_id)
                    ? $keyPerson->involveable_id
                    : null;

                $fallbackName = $personId === null
                    ? $keyPerson->display_name
                    : null;

                return [
                    'role_code' => (string) $keyPerson->role_code,
                    'involveable_type' => $personId === null ? null : 'person',
                    'involveable_id' => $personId,
                    'display_name' => filled($fallbackName) ? (string) $fallbackName : null,
                    'visibility' => $keyPerson->visibility ?? 'public',
                    'notes' => filled($keyPerson->notes) ? (string) $keyPerson->notes : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function organizerLocationDefaults(Event $duplicateEvent, ?User $actor): array
    {
        $access = app(EntitySubmissionAccess::class);
        $submitter = $actor;
        $defaults = [];
        $eventFormat = $duplicateEvent->delivery_mode instanceof EventFormat
            ? $duplicateEvent->delivery_mode->value
            : (is_string($duplicateEvent->delivery_mode) ? $duplicateEvent->delivery_mode : EventFormat::Physical->value);
        $organizer = $duplicateEvent->primaryOrganizerInvolvement;
        $organizerId = $organizer !== null ? $organizer->involveable_id : null;
        $institutionId = is_string($duplicateEvent->institution_id) ? $duplicateEvent->institution_id : null;

        if ($organizer?->involveable_type === Institution::class && $organizerId !== null && $access->canUseInstitution($submitter, $organizerId)) {
            $defaults['primary_organizer_kind'] = 'institution';
            $defaults['primary_organizer_id'] = $organizerId;
            $defaults['primary_organizer_institution_id'] = $organizerId;
            $defaults['primary_organizer_person_id'] = null;
        }

        if ($organizer?->involveable_type === Person::class && $organizerId !== null && $access->canUsePerson($submitter, $organizerId)) {
            $defaults['primary_organizer_kind'] = 'person';
            $defaults['primary_organizer_id'] = $organizerId;
            $defaults['primary_organizer_institution_id'] = null;
            $defaults['primary_organizer_person_id'] = $organizerId;
        }

        if ($eventFormat === EventFormat::Online->value) {
            return $defaults;
        }

        if (filled($duplicateEvent->default_venue_id)) {
            $defaults['location_same_as_institution'] = false;
            $defaults['location_type'] = 'venue';
            $defaults['location_venue_id'] = $duplicateEvent->default_venue_id;
            $defaults['location_institution_id'] = null;

            return $defaults;
        }

        if ($institutionId !== null && $access->canUseInstitution($submitter, $institutionId)) {
            $defaults['location_type'] = 'institution';
            $defaults['location_institution_id'] = $institutionId;
            $defaults['location_same_as_institution'] = ($defaults['primary_organizer_kind'] ?? null) === 'institution'
                && ($defaults['primary_organizer_id'] ?? null) === $institutionId;
        }

        return $defaults;
    }
}
