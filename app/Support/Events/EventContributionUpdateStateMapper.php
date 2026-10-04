<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Actions\Prayer\ResolvePrayerStartClockAction;
use App\Enums\EventPrayerTime;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Support\Prayer\PrayerLocation;
use App\Support\Prayer\PrayerTargetSelector;
use App\Support\Submission\SubmissionTimingPolicy;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon as IlluminateCarbon;

class EventContributionUpdateStateMapper
{
    /**
     * Normalized-state key => prayer metadata key. The event form state
     * carries no provenance keys, so these travel in normalized
     * contribution state (and in the diff originals below) to keep fresh
     * provenance flowing on timing changes.
     *
     * @var array<string, string>
     */
    private const array PRAYER_PROVENANCE_META_KEYS = [
        'prayer_source' => 'source',
        'prayer_fetched_at' => 'fetched_at',
        'prayer_zone' => 'zone',
        'prayer_country' => 'country',
        'prayer_date' => 'prayer_date',
        'prayer_lat' => 'lat',
        'prayer_lng' => 'lng',
        'prayer_venue_id' => 'venue_id',
        'prayer_institution_id' => 'institution_id',
    ];

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function toHelperState(array $state): array
    {
        $state = AdminEventTimeMapper::injectFormTimeFields($state);

        return self::injectOrganizerLocationFields($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function toPersistenceState(array $state, ?Event $event = null): array
    {
        $state = self::normalizeOrganizerLocationState($state);

        $provenance = null;
        $preservedTiming = null;

        if ($event instanceof Event) {
            [$state, $provenance, $preservedTiming] = self::injectContributionPrayerResolution($state, $event);
        }

        $state = AdminEventTimeMapper::normalizeForPersistence($state);

        if (($state['starts_at'] ?? null) instanceof CarbonInterface) {
            $state['starts_at'] = $state['starts_at']->toDateTimeString();
        }

        if (($state['ends_at'] ?? null) instanceof CarbonInterface) {
            $state['ends_at'] = $state['ends_at']->toDateTimeString();
        }

        if (is_array($provenance)) {
            foreach (self::PRAYER_PROVENANCE_META_KEYS as $stateKey => $metaKey) {
                $state[$stateKey] = $provenance[$stateKey] ?? null;
            }
        }

        if (is_array($preservedTiming)) {
            $state['prayer_reference'] = $preservedTiming['prayer_reference'];
            $state['prayer_offset'] = $preservedTiming['prayer_offset'];
            $state['prayer_display_text'] = $preservedTiming['prayer_display_text'];
        }

        return $state;
    }

    /**
     * Provenance side of contribution change detection.
     *
     * @return array<string, mixed>
     */
    public static function provenanceOriginals(Model $entity): array
    {
        $meta = $entity instanceof Event ? self::currentPrayerMeta($entity) : [];
        $originals = [];

        foreach (self::PRAYER_PROVENANCE_META_KEYS as $stateKey => $metaKey) {
            $originals[$stateKey] = $meta[$metaKey] ?? null;
        }

        return $originals;
    }

    /**
     * Resolves contribution prayer timing the way admin saves do: unchanged
     * timing/location inputs keep the persisted instant and provenance,
     * changed inputs re-resolve through the shared cache-only path. The
     * shared Friday/Ramadan calendar policy applies to the effective
     * merged state in both cases.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>|null}
     */
    private static function injectContributionPrayerResolution(array $state, Event $event): array
    {
        $event->loadMissing(['primaryOrganizerInvolvement', 'timeExpressions']);

        $timezone = self::normalizeOptionalString($state['timezone'] ?? null)
            ?? self::normalizeOptionalString($event->timezone)
            ?? 'Asia/Kuala_Lumpur';

        $currentMeta = self::currentPrayerMeta($event);

        [$countryIso, $targetInstitutionId, $targetVenueId] = self::effectivePrayerTargets($state, $event);
        $address = PrayerLocation::forTargets($targetVenueId, $targetInstitutionId);

        self::assertContributionCalendarEligibility($state, $timezone, $countryIso);

        if ($event->starts_at !== null && self::contributionTimingUnchanged($state, $event, $timezone)) {
            $startsAt = Carbon::parse($event->starts_at)->setTimezone($timezone);

            $state['resolved_start_clock'] = $startsAt->format('H:i');
            $state['resolved_start_date'] = $startsAt->toDateString();
            $state['resolved_start_instant'] = Carbon::parse($event->starts_at)->utc()->toIso8601String();

            $provenance = [];

            foreach (self::PRAYER_PROVENANCE_META_KEYS as $stateKey => $metaKey) {
                $provenance[$stateKey] = $currentMeta[$metaKey] ?? null;
            }

            // Normalization replaces the stored offset/display with preset
            // defaults; unchanged timing keeps the expression's exact
            // anchor, offset, and label instead.
            return [$state, $provenance, [
                'prayer_reference' => $event->prayer_reference,
                'prayer_offset' => $event->prayer_offset,
                'prayer_display_text' => $event->prayer_display_text,
            ]];
        }

        $prayerTime = EventPrayerTime::tryFrom((string) ($state['prayer_time'] ?? ''));
        $eventDate = AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone);
        $location = PrayerLocation::fromAddress($address, $countryIso);

        $resolved = $prayerTime instanceof EventPrayerTime && $eventDate !== null
            ? app(ResolvePrayerStartClockAction::class)->handle(
                countryCode: $countryIso,
                date: $eventDate,
                timezone: $timezone,
                prayerTime: $prayerTime,
                latitude: $location['latitude'],
                longitude: $location['longitude'],
                stateCode: $location['stateCode'],
                districtCandidates: $location['districtCandidates'],
            )
            : null;

        if (is_array($resolved)) {
            $state['resolved_start_clock'] = $resolved['clock'] ?? null;
            $state['resolved_start_date'] = $resolved['date'] ?? null;
            $state['resolved_start_instant'] = $resolved['starts_at'] ?? null;
        }

        return [$state, [
            'prayer_source' => $resolved['source'] ?? null,
            'prayer_fetched_at' => $resolved['fetched_at'] ?? null,
            'prayer_zone' => $resolved['zone'] ?? null,
            'prayer_country' => $countryIso,
            'prayer_date' => $eventDate,
            'prayer_lat' => $resolved['lat'] ?? null,
            'prayer_lng' => $resolved['lng'] ?? null,
            'prayer_venue_id' => $targetVenueId,
            'prayer_institution_id' => $targetInstitutionId,
        ], null];
    }

    /**
     * Effective prayer targets shared by persistence and the contribution
     * options UI: physical-target address country first, then the event's
     * pinned provenance country, then MY. $state must already be
     * organizer/location-normalized (see effectiveOptionsCountry for raw
     * form state).
     *
     * @param  array<string, mixed>  $state
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private static function effectivePrayerTargets(array $state, ?Event $event): array
    {
        [$targetInstitutionId, $targetVenueId] = PrayerTargetSelector::forSave(
            $state['event_format'] ?? $event?->delivery_mode,
            OrganizerResolver::find(self::normalizeOptionalString($state['primary_organizer_id'] ?? null)),
            self::normalizeOptionalString($state['institution_id'] ?? null),
            self::normalizeOptionalString($state['venue_id'] ?? null),
        );

        $address = PrayerLocation::forTargets($targetVenueId, $targetInstitutionId);
        $pinned = $event instanceof Event ? self::currentPrayerMeta($event)['country'] ?? null : null;
        $countryIso = PrayerLocation::fromAddress($address)['countryCode']
            ?? (is_string($pinned) && $pinned !== '' ? $pinned : null)
            ?? 'MY';

        return [$countryIso, $targetInstitutionId, $targetVenueId];
    }

    /**
     * Options-UI entry: normalizes raw form state before resolving, so
     * reactive option gating uses exactly the persistence selection and
     * pinned-country fallback.
     *
     * @param  array<string, mixed>  $formState
     */
    public static function effectiveOptionsCountry(array $formState, ?Event $event): string
    {
        return self::effectivePrayerTargets(self::normalizeOrganizerLocationState($formState), $event)[0];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function assertContributionCalendarEligibility(array $state, string $timezone, string $countryIso): void
    {
        $prayerTime = EventPrayerTime::tryFrom((string) ($state['prayer_time'] ?? ''));

        if (! $prayerTime instanceof EventPrayerTime) {
            return;
        }

        $eventDate = AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone);

        if ($eventDate === null) {
            return;
        }

        app(SubmissionTimingPolicy::class)->assertPrayerDateIsAllowed(
            $prayerTime,
            IlluminateCarbon::parse($eventDate, $timezone),
            $timezone,
            'data.',
            $countryIso,
        );
    }

    /**
     * Only keys present in the submission state participate: hidden form
     * fields (a prayer event's custom_time, for example) dehydrate absent
     * and must not read as changes against the record's derived values.
     *
     * @param  array<string, mixed>  $state
     */
    private static function contributionTimingUnchanged(array $state, Event $event, string $timezone): bool
    {
        $record = AdminEventTimeMapper::injectFormTimeFields([
            'starts_at' => $event->starts_at?->toISOString(),
            'ends_at' => $event->ends_at?->toISOString(),
            'timezone' => $event->timezone,
            'timing_mode' => $event->timing_mode instanceof BackedEnum ? $event->timing_mode->value : $event->timing_mode,
            'prayer_reference' => $event->prayer_reference,
            'prayer_offset' => $event->prayer_offset,
            'prayer_date' => self::currentPrayerMeta($event)['prayer_date'] ?? null,
        ]);

        $record['institution_id'] = $event->institution_id;
        $record['venue_id'] = $event->default_venue_id;
        $record['event_format'] = $event->delivery_mode instanceof BackedEnum ? $event->delivery_mode->value : $event->delivery_mode;
        $record['primary_organizer_id'] = $event->primaryOrganizerInvolvement?->involveable_id;

        if (array_key_exists('event_date', $state)) {
            $left = AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone);
            $right = AdminEventTimeMapper::normalizeEventDateString($record['event_date'] ?? null, $timezone);

            if ($left !== $right) {
                return false;
            }
        }

        foreach (['prayer_time', 'custom_time', 'end_time', 'end_date', 'timezone', 'institution_id', 'venue_id', 'event_format', 'primary_organizer_id'] as $key) {
            if (! array_key_exists($key, $state)) {
                continue;
            }

            if (self::normalizeTimingScalar($state[$key]) !== self::normalizeTimingScalar($record[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function normalizeTimingScalar(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function currentPrayerMeta(Event $event): array
    {
        $event->loadMissing('timeExpressions');

        $meta = $event->timeExpressions->firstWhere('anchor_type', 'prayer')?->metadata['prayer'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function injectOrganizerLocationFields(array $state): array
    {
        $primaryOrganizerId = self::normalizeOptionalString($state['primary_organizer_id'] ?? null);
        $organizerType = self::resolvedPrimaryOrganizerType($primaryOrganizerId);
        $institutionId = self::normalizeOptionalString($state['institution_id'] ?? null);
        $venueId = self::normalizeOptionalString($state['venue_id'] ?? null);

        $state['primary_organizer_kind'] = $organizerType;
        $state['primary_organizer_institution_id'] = $organizerType === 'institution' ? $primaryOrganizerId : null;
        $state['primary_organizer_person_id'] = $organizerType === 'person' ? $primaryOrganizerId : null;

        if ($organizerType === 'institution') {
            $sameAsInstitution = $venueId === null && $institutionId !== null && $institutionId === $primaryOrganizerId;

            $state['location_same_as_institution'] = $sameAsInstitution;
            $state['location_type'] = $venueId !== null ? 'venue' : 'institution';
            $state['location_institution_id'] = $sameAsInstitution ? $primaryOrganizerId : $institutionId;
            $state['location_venue_id'] = $venueId;

            return $state;
        }

        $state['location_same_as_institution'] = false;
        $state['location_type'] = $venueId !== null ? 'venue' : 'institution';
        $state['location_institution_id'] = $venueId === null ? $institutionId : null;
        $state['location_venue_id'] = $venueId;

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function normalizeOrganizerLocationState(array $state): array
    {
        $primaryOrganizerId = self::normalizeOptionalString($state['primary_organizer_id'] ?? null);
        $organizerType = self::resolvedPrimaryOrganizerType($primaryOrganizerId);
        $locationInstitutionId = self::normalizeOptionalString($state['location_institution_id'] ?? null);
        $locationVenueId = self::normalizeOptionalString($state['location_venue_id'] ?? null);
        $spaceId = self::normalizeOptionalString($state['space_id'] ?? null);
        $sameAsInstitution = (bool) ($state['location_same_as_institution'] ?? true);
        $locationType = in_array($state['location_type'] ?? null, ['institution', 'venue'], true)
            ? $state['location_type']
            : 'institution';

        $state['institution_id'] = null;
        $state['venue_id'] = null;

        if ($organizerType === 'institution') {
            if ($sameAsInstitution) {
                $state['institution_id'] = $primaryOrganizerId;
            } elseif ($locationType === 'institution') {
                $state['institution_id'] = $locationInstitutionId;
            } elseif ($locationType === 'venue') {
                $state['venue_id'] = $locationVenueId;
            }
        } elseif ($organizerType === 'person') {
            if ($locationType === 'institution') {
                $state['institution_id'] = $locationInstitutionId;
            } elseif ($locationType === 'venue') {
                $state['venue_id'] = $locationVenueId;
            }
        }

        $state['space_id'] = $state['institution_id'] !== null && $state['venue_id'] === null
            ? $spaceId
            : null;

        unset(
            $state['primary_organizer_kind'],
            $state['primary_organizer_institution_id'],
            $state['primary_organizer_person_id'],
            $state['location_same_as_institution'],
            $state['location_type'],
            $state['location_institution_id'],
            $state['location_venue_id'],
        );

        return $state;
    }

    private static function resolvedPrimaryOrganizerType(?string $primaryOrganizerId): ?string
    {
        if ($primaryOrganizerId === null) {
            return null;
        }

        if (Institution::query()->whereKey($primaryOrganizerId)->exists()) {
            return 'institution';
        }

        if (Person::query()->whereKey($primaryOrganizerId)->exists()) {
            return 'person';
        }

        return null;
    }

    private static function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
