<?php

namespace App\Support\Events;

use AIArmada\Addressing\Models\Address;
use AIArmada\Events\Enums\ScheduleKind;
use App\Actions\Prayer\ResolvePrayerStartClockAction;
use App\Enums\EventFormat;
use App\Enums\EventPrayerTime;
use App\Models\Event;
use App\Support\Prayer\PrayerLocation;
use App\Support\Prayer\PrayerTargetSelector;
use App\Support\Submission\SubmissionTimingPolicy;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type PreparedAdminEventInput from AdminEventInputPreparer
 */
final readonly class AdminEventScheduleResolver
{
    public function __construct(
        private ResolvePrayerStartClockAction $resolveStartClock,
        private SubmissionTimingPolicy $timing,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  PreparedAdminEventInput  $input
     * @return array{
     *     schedule: array<string, mixed>,
     *     schedule_kind: ScheduleKind,
     *     prayer_institution_id: string|null,
     *     prayer_venue_id: string|null
     * }
     */
    public function resolve(array $data, array $input, Event $event, bool $creating): array
    {
        $state = $input['state'];
        $recordState = $input['record_state'];
        $institutionId = $input['institution_id'];
        $venueId = $input['venue_id'];
        $primaryOrganizer = $input['primary_organizer'];

        // Prayer targets are independent of the persisted physical
        // location: online saves anchor to the organizer institution
        // through the same selector frontend submit/preview use.
        [$prayerInstitutionId, $prayerVenueId] = PrayerTargetSelector::forSave(
            $state['event_format'] ?? EventFormat::Physical->value,
            $primaryOrganizer,
            $institutionId,
            $venueId,
        );

        // Resolved once: the address feeds geo inputs and the effective
        // country feeds both resolution and fallback persistence.
        // Explicit country input is a deliberate override; an unchanged
        // inherited id is provenance, not an instruction.
        $prayerAddress = PrayerLocation::forTargets($prayerVenueId, $prayerInstitutionId);
        $recordCountryId = $creating ? null : $this->normalizeOptionalString($recordState['submission_country_id'] ?? null);
        $stateCountryId = $this->normalizeOptionalString($state['submission_country_id'] ?? null);
        $explicitCountry = $creating
            ? array_key_exists('submission_country_id', $data)
            : $stateCountryId !== $recordCountryId;
        $explicitCountryIso = $explicitCountry
            ? $this->timing->countryIso2ForId($state['submission_country_id'] ?? null)
            : null;
        $targetsChanged = $creating || $this->prayerTargetsChanged($state, $recordState);
        $prayerCountryIso = $this->prayerCountryIso(
            $prayerAddress,
            $explicitCountryIso,
            $targetsChanged,
            $creating ? null : $this->pinnedPrayerCountry($event),
        );

        $timezone = $this->normalizeRequiredString($state['timezone'] ?? $event->timezone, 'Asia/Kuala_Lumpur');

        // Unchanged timing is determined BEFORE provider resolution and
        // normalization: during an outage the fallback start can land after
        // the persisted end, and the end-time comparison below would reject
        // a title-only edit before persisted timing could be restored.
        // Rebuilding the resolved clock from the persisted start also skips
        // a pointless provider resolution on unrelated edits.
        $preserveTiming = ! $creating
            && $event->starts_at !== null
            && $this->timingInputsUnchanged($state, $recordState, $timezone);

        // Shared calendar eligibility (Friday Jumaat, Ramadan Tarawih)
        // applies on every save: persisted timing must satisfy the
        // current rule even when the edit touches nothing else.
        $this->assertCalendarEligibility($state, $timezone, $prayerCountryIso);

        $resolvedStart = $preserveTiming
            ? $this->persistedStartClock($event, $timezone)
            : $this->resolveProviderStartClock($state, $prayerAddress, $prayerCountryIso);

        $persistence = AdminEventTimeMapper::normalizeForPersistence(array_merge($state, [
            'resolved_start_clock' => $resolvedStart['clock'] ?? null,
            'resolved_start_date' => $resolvedStart['date'] ?? null,
            'resolved_start_instant' => $resolvedStart['starts_at'] ?? null,
            'prayer_source' => $resolvedStart['source'] ?? null,
            'prayer_fetched_at' => $resolvedStart['fetched_at'] ?? null,
            'prayer_zone' => $resolvedStart['zone'] ?? null,
            'prayer_lat' => $resolvedStart['lat'] ?? null,
            'prayer_lng' => $resolvedStart['lng'] ?? null,
        ]));
        $schedule = [
            'starts_at' => $persistence['starts_at'] ?? null,
            'ends_at' => $persistence['ends_at'] ?? null,
            'timezone' => $timezone,
            'timing_mode' => $persistence['timing_mode'] ?? null,
            'prayer_reference' => $persistence['prayer_reference'] ?? null,
            'prayer_offset' => $persistence['prayer_offset'] ?? null,
            'prayer_display_text' => $persistence['prayer_display_text'] ?? null,
            'prayer_source' => $persistence['prayer_source'] ?? null,
            'prayer_fetched_at' => $persistence['prayer_fetched_at'] ?? null,
            'prayer_zone' => $persistence['prayer_zone'] ?? null,
            'prayer_lat' => $persistence['prayer_lat'] ?? null,
            'prayer_lng' => $persistence['prayer_lng'] ?? null,
            'prayer_country' => $resolvedStart['country'] ?? $prayerCountryIso,
        ];

        // Unrelated edits (title, description, ...) must never move the
        // event's start: a title-only save during a provider outage would
        // otherwise overwrite a resolved 19:07 with the 20:00 estimate.
        // The decision was taken before normalization so the end-time
        // comparison above already ran against the persisted start.
        if ($preserveTiming) {
            $schedule['starts_at'] = $event->starts_at;
            $schedule['ends_at'] = $event->ends_at;

            // Normalization replaces the stored offset/display with preset
            // defaults; unchanged timing keeps the expression's exact
            // anchor, offset, and label instead.
            $schedule['prayer_reference'] = $event->prayer_reference;
            $schedule['prayer_offset'] = $event->prayer_offset;
            $schedule['prayer_display_text'] = $event->prayer_display_text;

            $prayerMeta = $event->timeExpressions
                ->first(fn ($expression): bool => $expression->anchor_type === 'prayer')
                ?->metadata['prayer'] ?? null;

            if (is_array($prayerMeta)) {
                $schedule['prayer_source'] = $prayerMeta['source'] ?? $schedule['prayer_source'];
                $schedule['prayer_fetched_at'] = $prayerMeta['fetched_at'] ?? $schedule['prayer_fetched_at'];
                $schedule['prayer_zone'] = $prayerMeta['zone'] ?? $schedule['prayer_zone'];
                $schedule['prayer_lat'] = $prayerMeta['lat'] ?? $schedule['prayer_lat'];
                $schedule['prayer_lng'] = $prayerMeta['lng'] ?? $schedule['prayer_lng'];
                $schedule['prayer_country'] = $prayerMeta['country'] ?? $schedule['prayer_country'];
            }
        }
        $scheduleKind = ScheduleKind::tryFrom((string) ($state['schedule_kind'] ?? $event->schedule_kind)) ?? ScheduleKind::Single;

        return [
            'schedule' => $schedule,
            'schedule_kind' => $scheduleKind,
            'prayer_institution_id' => $prayerInstitutionId,
            'prayer_venue_id' => $prayerVenueId,
        ];
    }

    /**
     * True when every timing-relevant input matches the record's current
     * form state. Dates normalize through the shared admin parser so a
     * localized d/m/Y input compares equal to its stored Y-m-d.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $recordState
     */
    private function timingInputsUnchanged(array $state, array $recordState, string $timezone): bool
    {
        $left = AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone);
        $right = AdminEventTimeMapper::normalizeEventDateString($recordState['event_date'] ?? null, $timezone);

        if ($left !== $right) {
            return false;
        }

        foreach (['prayer_time', 'custom_time', 'end_time', 'end_date', 'timezone', 'institution_id', 'venue_id', 'event_format', 'primary_organizer_id', 'submission_country_id'] as $key) {
            if ($this->normalizeTimingScalar($state[$key] ?? null) !== $this->normalizeTimingScalar($recordState[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeTimingScalar(mixed $value): ?string
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
     * Rebuilds a resolved clock/date pair from the persisted start so the
     * end-time comparison in normalization runs against real timing when
     * inputs are unchanged. Provenance is restored from the persisted
     * expression after normalization, never from a fresh resolution.
     *
     * @return array{clock: string, date: string, starts_at: string}
     */
    private function persistedStartClock(Event $event, string $timezone): array
    {
        $startsAt = Carbon::parse($event->starts_at)->setTimezone($timezone);

        return [
            'clock' => $startsAt->format('H:i'),
            'date' => $startsAt->toDateString(),
            'starts_at' => Carbon::parse($event->starts_at)->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{clock: string, date: string, starts_at: string, source: string, fetched_at: string, zone: string|null}|null
     */
    private function resolveProviderStartClock(array $state, ?Address $address, string $countryIso): ?array
    {
        $prayerTime = EventPrayerTime::tryFrom((string) ($state['prayer_time'] ?? ''));
        $timezone = (string) ($state['timezone'] ?? 'Asia/Kuala_Lumpur');
        $eventDate = AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone);

        if (! $prayerTime instanceof EventPrayerTime || $eventDate === null) {
            return null;
        }

        $location = PrayerLocation::fromAddress($address);

        return $this->resolveStartClock->handle(
            countryCode: $countryIso,
            date: $eventDate,
            timezone: $timezone,
            prayerTime: $prayerTime,
            latitude: $location['latitude'],
            longitude: $location['longitude'],
            stateCode: $location['stateCode'],
            districtCandidates: $location['districtCandidates'],
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertCalendarEligibility(array $state, string $timezone, string $countryIso): void
    {
        $prayerTime = EventPrayerTime::tryFrom((string) ($state['prayer_time'] ?? ''));

        if (! $prayerTime instanceof EventPrayerTime) {
            return;
        }

        $eventDate = AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $timezone);

        if ($eventDate === null) {
            return;
        }

        $this->timing->assertPrayerDateIsAllowed(
            $prayerTime,
            IlluminateCarbon::parse($eventDate, $timezone),
            $timezone,
            '',
            $countryIso,
        );
    }

    /**
     * Effective prayer country. Explicit input wins but must agree with
     * the effective address, mirroring frontend submission validation.
     * Without explicit input, changed targets follow the new address;
     * unchanged or address-less targets retain the pinned country.
     */
    private function prayerCountryIso(?Address $address, ?string $explicitCountryIso, bool $targetsChanged, ?string $pinnedCountryIso): string
    {
        $addressCountry = PrayerLocation::fromAddress($address)['countryCode'] ?? null;
        $addressCountry = is_string($addressCountry) && trim($addressCountry) !== '' ? $addressCountry : null;

        if ($explicitCountryIso !== null) {
            if ($addressCountry !== null && strtoupper($addressCountry) !== strtoupper($explicitCountryIso)) {
                throw ValidationException::withMessages([
                    'submission_country_id' => __('Negara yang dipilih tidak sepadan dengan lokasi yang dipilih.'),
                ]);
            }

            return $explicitCountryIso;
        }

        if ($targetsChanged) {
            return $addressCountry ?? $pinnedCountryIso ?? 'MY';
        }

        return $pinnedCountryIso ?? $addressCountry ?? 'MY';
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $recordState
     */
    private function prayerTargetsChanged(array $state, array $recordState): bool
    {
        foreach (['event_format', 'primary_organizer_id', 'institution_id', 'venue_id'] as $key) {
            if ($this->normalizeTimingScalar($state[$key] ?? null) !== $this->normalizeTimingScalar($recordState[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function pinnedPrayerCountry(Event $event): ?string
    {
        $event->loadMissing('timeExpressions');

        $country = $event->timeExpressions
            ->first(fn ($expression): bool => $expression->anchor_type === 'prayer'
                && $expression->event_occurrence_id === null
                && $expression->event_session_id === null)
            ?->metadata['prayer']['country'] ?? null;

        return is_string($country) && trim($country) !== '' ? $country : null;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        return $this->normalizeOptionalString($value) ?? $fallback;
    }
}
