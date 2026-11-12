<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Enums\ScheduleKind;
use App\Features\Events\Actions\Scheduling\SyncEventScheduleFromInputAction;
use App\Features\Events\Api\EventScheduleInput;
use App\Features\Events\Support\EventModel;
use App\Shared\Directory\SpaceEligibilityResolver;
use App\Shared\Events\Enums\TimingMode;
use App\Shared\Events\OrganizerResolver;
use App\Shared\Prayer\PrayerOffset;
use App\Shared\Support\MorphAlias;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Apply a normalized contribution payload to an existing event.
 *
 * This is the contribution-update writer for the event aggregate: it keeps the
 * persistence-state semantics (UTC schedule strings, explicit-null prayer
 * anchor, event_date-driven end-date clearing) that the contribution transport
 * already produces. It never re-derives state from the admin form contract.
 */
final readonly class ApplyEventContributionAction
{
    public function __construct(
        private SyncEventScheduleFromInputAction $syncSchedule,
        private SyncEventKeyPeopleAction $syncKeyPeople,
        private SyncEventClassificationsAction $syncClassifications,
        private SpaceEligibilityResolver $spaceEligibilityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Changed attributes.
     */
    public function handle(string $eventId, array $payload): array
    {
        $event = EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);

        $event->loadMissing(['primaryOccurrence', 'timeExpressions', 'locations']);
        $currentOccurrence = $event->primaryOccurrence;
        $currentExpression = $event->timeExpressions->firstWhere('anchor_type', 'prayer');

        $event->forceFill([
            'title' => $payload['title'] ?? $event->title,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $event->description,
            'timezone' => array_key_exists('timezone', $payload) ? $payload['timezone'] : $event->timezone,
            'gender' => array_key_exists('gender', $payload) ? $payload['gender'] : $event->gender,
            'age_group' => array_key_exists('age_group', $payload) ? $this->normalizeStringArray($payload['age_group']) : $event->age_group,
            'children_allowed' => array_key_exists('children_allowed', $payload) ? (bool) $payload['children_allowed'] : $event->children_allowed,
            'is_muslim_only' => array_key_exists('is_muslim_only', $payload) ? (bool) $payload['is_muslim_only'] : $event->is_muslim_only,
            'delivery_mode' => array_key_exists('event_format', $payload) ? $payload['event_format'] : $event->delivery_mode,
            'visibility' => array_key_exists('visibility', $payload) ? $payload['visibility'] : $event->visibility,
            'event_url' => array_key_exists('event_url', $payload) ? $this->normalizeOptionalString($payload['event_url']) : $event->event_url,
            'live_url' => array_key_exists('live_url', $payload) ? $this->normalizeOptionalString($payload['live_url']) : $event->live_url,
            'recording_url' => array_key_exists('recording_url', $payload) ? $this->normalizeOptionalString($payload['recording_url']) : $event->recording_url,
            'institution_id' => array_key_exists('institution_id', $payload) ? $this->normalizeOptionalString($payload['institution_id']) : $event->institution_id,
            'default_venue_id' => array_key_exists('venue_id', $payload) ? $this->normalizeOptionalString($payload['venue_id']) : $event->default_venue_id,
        ]);
        if (array_key_exists('space_ids', $payload)) {
            $spaceIds = is_array($payload['space_ids']) ? array_values(array_filter($payload['space_ids'], is_string(...))) : [];
        } else {
            $existing = $event->locations
                ->whereNull('event_occurrence_id')
                ->whereNull('event_session_id')
                ->sortBy('sort_order');
            $spaceIds = $existing->pluck('venue_space_id')->filter()->map(strval(...))->values()->all();
        }

        if ($spaceIds !== []) {
            if ($event->default_venue_id !== null) {
                $this->spaceEligibilityResolver->validateVenueSelection((string) $event->default_venue_id, $spaceIds);
            } elseif ($event->institution_id !== null) {
                $this->spaceEligibilityResolver->validateInstitutionSelection((string) $event->institution_id, $spaceIds);
            } else {
                throw ValidationException::withMessages([
                    'space_ids' => __('Ruang memerlukan institusi atau venue.'),
                ]);
            }
        }

        $event->syncLocation($event->default_venue_id, $spaceIds);

        $dirty = $event->getDirty();
        $event->save();

        $scheduleKind = $payload['schedule_kind'] ?? $event->schedule_kind;
        $scheduleKind = $scheduleKind instanceof ScheduleKind
            ? $scheduleKind
            : (ScheduleKind::tryFrom((string) $scheduleKind) ?? ScheduleKind::Single);
        $timingMode = $payload['timing_mode'] ?? ($currentExpression !== null ? TimingMode::PrayerRelative->value : TimingMode::Absolute->value);
        $timingMode = $timingMode instanceof TimingMode
            ? $timingMode
            : TimingMode::tryFrom((string) $timingMode);
        $currentSignedOffset = $currentExpression?->offset_minutes === null
            ? null
            : ($currentExpression->relation === 'before' ? -$currentExpression->offset_minutes : $currentExpression->offset_minutes);
        $prayerOffset = $payload['prayer_offset'] ?? $currentSignedOffset;
        $prayerOffset = $prayerOffset instanceof PrayerOffset
            ? $prayerOffset->minutes()
            : (is_numeric($prayerOffset)
                ? (int) $prayerOffset
                : PrayerOffset::tryFrom((string) $prayerOffset)?->minutes());

        $scheduleTimezone = is_string($event->timezone) && $event->timezone !== ''
            ? $event->timezone
            : 'UTC';
        // Persistence-state strings are UTC wall time: the contribution
        // mapper stringifies UTC Carbons, and the package writer stores
        // DateTime input as an absolute moment. Parsing them as schedule
        // local time double-shifts by the UTC offset.
        $startsAt = array_key_exists('starts_at', $payload) ? $payload['starts_at'] : $currentOccurrence?->starts_at;
        $startsAt = $startsAt instanceof CarbonInterface
            ? $startsAt
            : (is_string($startsAt) && $startsAt !== '' ? Carbon::parse($startsAt, 'UTC') : null);
        $endsAt = array_key_exists('ends_at', $payload) ? $payload['ends_at'] : $currentOccurrence?->ends_at;
        $endsAt = $endsAt instanceof CarbonInterface
            ? $endsAt
            : (is_string($endsAt) && $endsAt !== '' ? Carbon::parse($endsAt, 'UTC') : null);

        // An explicit null reference is a real change (Tarawih stores a null
        // anchor); only an omitted key falls back to the current anchor.
        $prayerReference = array_key_exists('prayer_reference', $payload)
            ? $payload['prayer_reference']
            : $currentExpression?->anchor_code;

        $currentPrayerMeta = $currentExpression?->metadata['prayer'] ?? null;
        $currentPrayerMeta = is_array($currentPrayerMeta) ? $currentPrayerMeta : [];

        $this->syncSchedule->handle((string) $event->getKey(), new EventScheduleInput(
            scheduleKind: $scheduleKind->value,
            startsAt: $startsAt?->toIso8601String(),
            endsAt: $endsAt?->toIso8601String(),
            timezone: $scheduleTimezone,
            timingMode: $timingMode?->value,
            prayerReference: $prayerReference,
            prayerOffset: $prayerOffset,
            prayerDisplayText: array_key_exists('prayer_display_text', $payload)
                ? $this->normalizeOptionalString($payload['prayer_display_text'])
                : $currentExpression?->display_label,
            prayerSource: array_key_exists('prayer_source', $payload)
                ? $this->normalizeOptionalString($payload['prayer_source'])
                : $this->normalizeMetaString($currentPrayerMeta['source'] ?? null),
            prayerFetchedAt: array_key_exists('prayer_fetched_at', $payload)
                ? $this->normalizeOptionalString($payload['prayer_fetched_at'])
                : $this->normalizeMetaString($currentPrayerMeta['fetched_at'] ?? null),
            prayerZone: array_key_exists('prayer_zone', $payload)
                ? $this->normalizeOptionalString($payload['prayer_zone'])
                : $this->normalizeMetaString($currentPrayerMeta['zone'] ?? null),
            prayerDate: array_key_exists('prayer_date', $payload) && is_string($payload['prayer_date'])
                ? $payload['prayer_date']
                : $this->normalizeMetaString($currentPrayerMeta['prayer_date'] ?? null),
            prayerLat: $this->resolveProvenanceFloat($payload['prayer_lat'] ?? null, $currentPrayerMeta['lat'] ?? null, array_key_exists('prayer_lat', $payload)),
            prayerLng: $this->resolveProvenanceFloat($payload['prayer_lng'] ?? null, $currentPrayerMeta['lng'] ?? null, array_key_exists('prayer_lng', $payload)),
            prayerVenueId: array_key_exists('prayer_venue_id', $payload)
                ? $this->normalizeOptionalString($payload['prayer_venue_id'])
                : $this->normalizeMetaString($currentPrayerMeta['venue_id'] ?? null),
            prayerInstitutionId: array_key_exists('prayer_institution_id', $payload)
                ? $this->normalizeOptionalString($payload['prayer_institution_id'])
                : $this->normalizeMetaString($currentPrayerMeta['institution_id'] ?? null),
            prayerCountry: array_key_exists('prayer_country', $payload)
                ? $this->normalizeOptionalString($payload['prayer_country'])
                : $this->normalizeMetaString($currentPrayerMeta['country'] ?? null),
        ));

        if (array_key_exists('primary_organizer_id', $payload)) {
            $organizerId = $this->normalizeOptionalString($payload['primary_organizer_id']);

            $event->setPrimaryOrganizer(OrganizerResolver::find($organizerId));
        }

        if (array_key_exists('language_ids', $payload)) {
            $languageIds = $this->normalizeStringArray($payload['language_ids']);
            $event->auditSync('languages', $languageIds, true, ['languages.id', 'languages.name']);
        }

        if (array_key_exists('reference_ids', $payload)) {
            $referenceIds = $this->normalizeStringArray($payload['reference_ids']);
            $event->auditSync('references', $referenceIds, true, ['references.id', 'references.title']);
        }

        if (array_key_exists('series_ids', $payload)) {
            $seriesIds = $this->normalizeStringArray($payload['series_ids']);
            $series = EventModel::new('series');
            $event->auditSync('series', $seriesIds, true, [$series->qualifyColumn('id'), $series->qualifyColumn('title')]);
        }

        if (
            array_key_exists('person_ids', $payload)
            || array_key_exists('other_key_people', $payload)
        ) {
            $this->syncKeyPeople->syncEvent(
                $event,
                $this->normalizeStringArray($payload['person_ids'] ?? []),
                $this->normalizeKeyPeople($payload['other_key_people'] ?? []),
            );
        }

        if (
            array_key_exists('event_category_ids', $payload)
            || array_key_exists('domain_tags', $payload)
            || array_key_exists('discipline_tags', $payload)
            || array_key_exists('source_tags', $payload)
            || array_key_exists('issue_tags', $payload)
        ) {
            $this->syncClassifications->handle($event, [
                'event_category_ids' => $payload['event_category_ids'] ?? $event->event_category_ids,
                'domain_tags' => $payload['domain_tags'] ?? [],
                'discipline_tags' => $payload['discipline_tags'] ?? [],
                'source_tags' => $payload['source_tags'] ?? [],
                'issue_tags' => $payload['issue_tags'] ?? [],
            ]);
        }

        return $dirty;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeMetaString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function resolveProvenanceFloat(mixed $payloadValue, mixed $metaValue, bool $payloadHasKey): ?float
    {
        if ($payloadHasKey) {
            return is_numeric($payloadValue) ? (float) $payloadValue : null;
        }

        return is_numeric($metaValue) ? (float) $metaValue : null;
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

    /**
     * @param  iterable<int, mixed>  $entries
     * @return list<array{role_code: string, involveable_type: string|null, involveable_id: string|null, display_name: string|null, visibility: string, notes: string|null}>
     */
    private function normalizeKeyPeople(iterable $entries): array
    {
        return collect($entries)
            ->map(function (mixed $entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $role = is_string($entry['role_code'] ?? null) ? trim($entry['role_code']) : null;
                $involveableType = is_string($entry['involveable_type'] ?? null) && $entry['involveable_type'] !== ''
                    ? MorphAlias::of($entry['involveable_type'])
                    : null;
                $involveableId = is_string($entry['involveable_id'] ?? null) && $entry['involveable_id'] !== '' ? $entry['involveable_id'] : null;
                $name = is_string($entry['display_name'] ?? null) ? trim($entry['display_name']) : null;
                $notes = is_string($entry['notes'] ?? null) ? trim($entry['notes']) : null;

                if ($role === null || $role === '' || ($involveableId === null && ($name === null || $name === ''))) {
                    return null;
                }

                return [
                    'role_code' => $role,
                    'involveable_type' => $involveableType,
                    'involveable_id' => $involveableId,
                    'display_name' => $name !== '' ? $name : null,
                    'visibility' => in_array($entry['visibility'] ?? 'public', ['public', 'private'], true) ? $entry['visibility'] : 'public',
                    'notes' => $notes !== '' ? $notes : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
