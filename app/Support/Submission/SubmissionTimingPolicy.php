<?php

declare(strict_types=1);

namespace App\Support\Submission;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Support\AddressCountryResolver;
use App\Actions\Prayer\BuildPrayerPreviewAction;
use App\Enums\EventPrayerTime;
use App\Models\Institution;
use App\Models\Person;
use App\Services\Prayer\HardcodedPrayerFallback;
use App\Services\Prayer\RamadanGate;
use App\Support\Prayer\PrayerLocation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared submission timing policy for the public event form and its action.
 *
 * Owns Ramadhan windows and strict date/time derivation so UI hints and
 * submit validation cannot drift apart. The prayer-time approximation map
 * lives in HardcodedPrayerFallback and is shared by every consumer.
 * Stored datetimes are always UTC; display-timezone concerns stay with callers.
 */
final class SubmissionTimingPolicy
{
    /**
     * Preferred representative when a country's linked zones share one
     * contemporary clock behavior, keyed by uppercase ISO2. Only selected
     * when actually linked; other countries keep stable canonical order.
     * Representatives follow IANA zone.tab/zone1970.tab signals (national
     * capital or the "most of" coverage comment).
     *
     * @var array<string, string>
     */
    private const PREFERRED_SUBMISSION_TIMEZONES = [
        'AR' => 'America/Argentina/Buenos_Aires',
        'CY' => 'Asia/Nicosia',
        'DE' => 'Europe/Berlin',
        'KZ' => 'Asia/Almaty',
        'MH' => 'Pacific/Majuro',
        'MY' => 'Asia/Kuala_Lumpur',
        // First-listed PS zone; Gaza and Hebron share one clock since 2012.
        'PS' => 'Asia/Gaza',
        'UZ' => 'Asia/Tashkent',
    ];

    /**
     * Bounded contemporary window for clock-behavior comparison, in years
     * from now. Historical differences must not keep a timezone selector
     * visible; only divergence within this window requires a choice.
     */
    private const EQUIVALENT_CLOCK_COMPARISON_YEARS = 10;

    /**
     * @return array<string, string>
     */
    public function defaultPrayerTimes(): array
    {
        return HardcodedPrayerFallback::MAP;
    }

    public function resolvePrayerTime(mixed $value, string $validationKeyPrefix = ''): EventPrayerTime
    {
        if ($value instanceof EventPrayerTime) {
            return $value;
        }

        $prayerTime = EventPrayerTime::tryFrom((string) $value);

        if (! $prayerTime instanceof EventPrayerTime) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('prayer_time', $validationKeyPrefix) => __('Sila pilih waktu majlis yang sah.'),
            ]);
        }

        return $prayerTime;
    }

    public function parseEventDate(mixed $value, string $timezone, string $validationKeyPrefix = ''): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_date', $validationKeyPrefix) => __('Sila pilih tarikh majlis.'),
            ]);
        }

        // Strict date-only boundary: Carbon::parse would roll impossible
        // dates forward (Feb 31 becomes Mar 3) and accept relative text.
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches) !== 1
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        ) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_date', $validationKeyPrefix) => __('Tarikh majlis tidak sah. Sila semak semula.'),
            ]);
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $matches[0], $timezone)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_date', $validationKeyPrefix) => __('Tarikh majlis tidak sah. Sila semak semula.'),
            ]);
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function parseClockTime(mixed $value, string $field, string $validationKeyPrefix = ''): array
    {
        if (! is_string($value) || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $matches) !== 1) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey($field, $validationKeyPrefix) => __('Masa yang dimasukkan tidak sah. Sila gunakan format jam:minit.'),
            ]);
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    public function resolveStartsAt(
        mixed $eventDate,
        mixed $prayerTimeValue,
        mixed $customTime,
        string $timezone,
        string $validationKeyPrefix = '',
        ?string $resolvedClock = null,
        ?string $resolvedDate = null,
        ?string $resolvedInstant = null,
    ): Carbon {
        $date = $this->parseEventDate($eventDate, $timezone, $validationKeyPrefix);
        $prayerTime = $this->resolvePrayerTime($prayerTimeValue, $validationKeyPrefix);

        // The resolved UTC instant stores directly: rebuilding it from wall
        // text would reintroduce DST-fold ambiguity (second occurrence
        // reads back as the first). A corrupt instant falls through to the
        // clock path, never rejecting the user's submit.
        if (! $prayerTime->isCustomTime() && $resolvedInstant !== null) {
            try {
                return Carbon::parse($resolvedInstant, 'UTC')->utc();
            } catch (Throwable) {
                // Fall through to the clock path below.
            }
        }

        if (! $prayerTime->isCustomTime() && $resolvedClock !== null) {
            try {
                [$hour, $minute] = $this->parseClockTime($resolvedClock, 'prayer_time', $validationKeyPrefix);
                // Provider offsets can roll past midnight; the resolved
                // local date travels with the clock.
                $day = $resolvedDate !== null
                    ? $this->parseEventDate($resolvedDate, $timezone, $validationKeyPrefix)
                    : $date;

                return $day->setTime($hour, $minute)->utc();
            } catch (ValidationException) {
                // A corrupt provider clock degrades to the hardcoded
                // estimate; our data must never reject the user's submit.
            }
        }

        if ($prayerTime->isCustomTime()) {
            if (! is_string($customTime) || trim($customTime) === '') {
                throw ValidationException::withMessages([
                    SubmissionValues::prefixedKey('custom_time', $validationKeyPrefix) => __('Sila pilih masa mula majlis.'),
                ]);
            }

            [$hour, $minute] = $this->parseClockTime($customTime, 'custom_time', $validationKeyPrefix);

            return $this->resolveLocalClockTime($date, $hour, $minute, 'custom_time', $validationKeyPrefix)->utc();
        }

        $timeString = $this->defaultPrayerTimes()[$prayerTime->value] ?? null;

        if ($timeString === null) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('prayer_time', $validationKeyPrefix) => __('Sila pilih waktu majlis yang sah.'),
            ]);
        }

        [$hour, $minute] = $this->parseClockTime($timeString, 'prayer_time', $validationKeyPrefix);

        return $date->setTime($hour, $minute)->utc();
    }

    public function resolveEndsAt(
        mixed $endTime,
        Carbon $startsAt,
        string $timezone,
        string $validationKeyPrefix = '',
    ): ?Carbon {
        if ($endTime === null || (is_string($endTime) && trim($endTime) === '')) {
            return null;
        }

        [$hour, $minute] = $this->parseClockTime($endTime, 'end_time', $validationKeyPrefix);

        return $this->resolveLocalClockTime($startsAt->copy()->setTimezone($timezone), $hour, $minute, 'end_time', $validationKeyPrefix)->utc();
    }

    public function validateEndsAtAfterStartsAt(
        mixed $endTime,
        Carbon $startsAt,
        string $timezone,
        string $validationKeyPrefix = '',
    ): void {
        if ($endTime === null || (is_string($endTime) && trim($endTime) === '')) {
            return;
        }

        [$hour, $minute] = $this->parseClockTime($endTime, 'end_time', $validationKeyPrefix);
        $startInUserTimezone = $startsAt->copy()->setTimezone($timezone);
        $endInUserTimezone = $this->resolveLocalClockTime($startInUserTimezone, $hour, $minute, 'end_time', $validationKeyPrefix);

        if ($endInUserTimezone->lessThanOrEqualTo($startInUserTimezone)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('end_time', $validationKeyPrefix) => __('Masa akhir mestilah selepas masa mula.'),
            ]);
        }
    }

    public function validateStartsAtIsFuture(
        Carbon $startsAt,
        string $timezone,
        EventPrayerTime $prayerTime,
        string $validationKeyPrefix = '',
    ): void {
        if ($startsAt->greaterThan(Carbon::now($timezone))) {
            return;
        }

        $errorField = $prayerTime->isCustomTime() ? 'custom_time' : 'prayer_time';

        throw ValidationException::withMessages([
            SubmissionValues::prefixedKey($errorField, $validationKeyPrefix) => __('Waktu majlis yang dipilih telah berlalu. Sila pilih waktu lain.'),
        ]);
    }

    public function assertPrayerDateIsAllowed(
        EventPrayerTime $prayerTime,
        Carbon $eventDate,
        string $timezone,
        string $validationKeyPrefix = '',
        ?string $countryCode = null,
    ): void {
        if (
            in_array($prayerTime, [EventPrayerTime::SebelumJumaat, EventPrayerTime::SelepasJumaat], true)
            && ! $eventDate->isFriday()
        ) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('prayer_time', $validationKeyPrefix) => __('Pilihan waktu Jumaat hanya boleh dipilih untuk hari Jumaat.'),
            ]);
        }

        if ($prayerTime === EventPrayerTime::SelepasTarawih && ! $this->isRamadhan($eventDate, $timezone, $countryCode)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('prayer_time', $validationKeyPrefix) => __('Selepas Tarawih hanya boleh dipilih semasa bulan Ramadhan.'),
            ]);
        }

        if ($prayerTime === EventPrayerTime::SelepasZuhur && $eventDate->isFriday()) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('prayer_time', $validationKeyPrefix) => __('Selepas Zuhur hanya boleh dipilih selain hari Jumaat.'),
            ]);
        }
    }

    public function isPrayerTimeAvailable(mixed $value, mixed $eventDate, string $timezone, ?string $countryCode = null): bool
    {
        $prayerTime = $value instanceof EventPrayerTime
            ? $value
            : EventPrayerTime::tryFrom((string) $value);

        if (! $prayerTime instanceof EventPrayerTime) {
            return false;
        }

        if (! is_string($eventDate) || trim($eventDate) === '') {
            return ! in_array($prayerTime, [
                EventPrayerTime::SebelumJumaat,
                EventPrayerTime::SelepasJumaat,
                EventPrayerTime::SelepasTarawih,
            ], true);
        }

        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($eventDate), $matches) !== 1
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        ) {
            return false;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $matches[0], $timezone)->startOfDay();
        } catch (Throwable) {
            return false;
        }

        return match ($prayerTime) {
            EventPrayerTime::SebelumJumaat,
            EventPrayerTime::SelepasJumaat => $date->isFriday(),
            EventPrayerTime::SelepasZuhur => ! $date->isFriday(),
            EventPrayerTime::SelepasTarawih => $this->isRamadhan($date, $timezone, $countryCode),
            default => true,
        };
    }

    /**
     * @param  array<string, string|null>|null  $previewStarts  exact per-label clocks from the hidden form preview
     * @param  array<string, mixed>|null  $formState  current form state for a fresh cache-only resolution
     */
    public function resolveStartTimeForComparison(mixed $prayerTimeValue, mixed $customTime, ?array $previewStarts = null, ?array $formState = null): ?string
    {
        $prayerTime = $prayerTimeValue instanceof EventPrayerTime
            ? $prayerTimeValue
            : EventPrayerTime::tryFrom((string) $prayerTimeValue);

        if ($prayerTime?->isCustomTime()) {
            return is_string($customTime) && $customTime !== '' ? $customTime : null;
        }

        if (! $prayerTime instanceof EventPrayerTime) {
            return null;
        }

        // Exact starts first: comparing a valid end (19:30) against the
        // hardcoded estimate (20:00) would wrongly reject it when the
        // provider start (19:07) is known.
        $exact = $previewStarts[$prayerTime->value] ?? null;

        if (! is_string($exact) || preg_match('/^\d{1,2}:\d{2}$/', $exact) !== 1) {
            $exact = $this->defaultPrayerTimes()[$prayerTime->value] ?? null;
        }

        // The hidden hint is a snapshot: deferred warming after the hint was
        // recorded would otherwise reject ends the submit itself accepts. A
        // fresh cache-only resolution runs in the same request as the
        // comparison, and the earlier of the two clocks wins so neither a
        // stale hint nor a just-expired cache can block a valid end.
        $fresh = $formState !== null ? $this->freshPreviewStart($formState, $prayerTime) : null;

        if ($fresh === null) {
            return $exact;
        }

        if ($exact === null) {
            return $fresh;
        }

        return $this->toMinutes($fresh) <= $this->toMinutes($exact) ? $fresh : $exact;
    }

    /**
     * Fresh cache-only preview for the given form state. Shared by hint
     * generation and end-time validation so both read the same clocks.
     * Never performs live HTTP; null when the state cannot resolve yet.
     *
     * @param  array<string, mixed>  $state
     * @return array{date: string, country: string, timezone: string, zone: string|null, location_source: string|null, source: string, exact: bool, stale: bool, fetched_at: string|null, starts: array<string, string|null>}|null
     */
    public function freshPrayerPreview(array $state): ?array
    {
        if (! config('prayer.enabled')) {
            return null;
        }

        $date = $state['event_date'] ?? null;

        if (! is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) !== 1 || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }

        $countryId = $state['submission_country_id'] ?? null;
        $country = is_string($countryId) && $countryId !== '' ? AddressCountry::query()->find($countryId) : null;
        $address = $this->previewLocationAddress($state);
        $timezone = $state['submission_timezone'] ?? null;
        $location = PrayerLocation::fromAddress($address, $country?->iso2);

        return app(BuildPrayerPreviewAction::class)->handle(
            country: $location['countryCode'] ?? 'MY',
            date: $date,
            timezone: is_string($timezone) && $timezone !== '' ? $timezone : null,
            latitude: $location['latitude'],
            longitude: $location['longitude'],
            stateCode: $location['stateCode'],
            allowLive: false,
            districtCandidates: $location['districtCandidates'],
        );
    }

    /**
     * Preview-side submit target resolution. Uses the SAME resolver as
     * submit (including the online→organizer-institution branch) so hints
     * and submits resolve the identical address. Partial form state that
     * submit would reject yields no preview address.
     *
     * @param  array<string, mixed>  $state
     */
    private function previewLocationAddress(array $state): ?Address
    {
        try {
            $context = app(SubmissionContextResolver::class);
            $organizer = $context->resolvePrimaryOrganizer($state['primary_organizer_id'] ?? null);

            if (! $organizer instanceof Institution && ! $organizer instanceof Person) {
                return null;
            }

            [$institutionId, $venueId] = $context->resolveTargetLocation($state, $organizer);
        } catch (Throwable) {
            return null;
        }

        if ($venueId === null && $institutionId === null) {
            return null;
        }

        return PrayerLocation::forTargets($venueId, $institutionId);
    }

    /**
     * @param  array<string, mixed>  $formState
     */
    private function freshPreviewStart(array $formState, EventPrayerTime $prayerTime): ?string
    {
        try {
            $starts = $this->freshPrayerPreview($formState)['starts'] ?? null;
        } catch (Throwable) {
            return null;
        }

        $clock = is_array($starts) ? ($starts[$prayerTime->value] ?? null) : null;

        return is_string($clock) && preg_match('/^\d{1,2}:\d{2}$/', $clock) === 1 ? $clock : null;
    }

    private function toMinutes(string $clock): int
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $clock) + [0, 0]);

        return $hour * 60 + $minute;
    }

    public function isRamadhan(Carbon $date, ?string $timezone = null, ?string $countryCode = null): bool
    {
        return app(RamadanGate::class)->isRamadan($date, $timezone, $countryCode);
    }

    /**
     * Submission country id (UUID) → ISO2 declaration scope for the
     * Ramadan gate. Unknown ids resolve null and use the estimate.
     */
    public function countryIso2ForId(mixed $countryId): ?string
    {
        $id = $this->resolveSubmissionCountryId($countryId);

        if ($id === null) {
            return null;
        }

        $iso2 = AddressCountry::query()->whereKey($id)->value('iso2');

        return is_string($iso2) && trim($iso2) !== '' ? strtoupper(trim($iso2)) : null;
    }

    public function resolveSubmissionCountryId(mixed $countryId, ?string $fallbackCountryCode = null): ?string
    {
        $resolvedCountryId = app(AddressCountryResolver::class)->resolveId($countryId);

        if (is_string($resolvedCountryId)) {
            return $resolvedCountryId;
        }

        if (($countryId === null || (is_string($countryId) && trim($countryId) === '')) && $fallbackCountryCode !== null) {
            $fallback = app(AddressCountryResolver::class)->resolveId($fallbackCountryCode);

            return is_string($fallback) ? $fallback : null;
        }

        return null;
    }

    /**
     * Effective country -> timezones catalog, bounded to two queries.
     *
     * Eager-loads every country's linked timezones once, applies the shared
     * linked-timezone normalization, then collapses linked zones that share
     * one contemporary clock behavior to a single representative. Keyed by
     * country UUID in country-name order. Powers multi-timezone detection
     * (form progress config, API contract) without per-country query loops.
     *
     * @return array<string, list<string>>
     */
    public function countryTimezoneCatalog(): array
    {
        $countries = AddressCountry::query()
            ->orderBy('name')
            ->with(['timezones' => function (Relation $query): void {
                $query->orderBy('name');
            }])
            ->get(['id', 'iso2']);

        $catalog = [];

        foreach ($countries as $country) {
            $catalog[(string) $country->getKey()] = $this->collapseEquivalentClockTimezones(
                $this->normalizeTimezoneNames($country->timezones->pluck('name')->all()),
                $this->normalizeCountryIso2($country->getAttribute('iso2')),
            );
        }

        return $catalog;
    }

    /**
     * Effective submission timezones for a country, deduplicated.
     *
     * Reads the `AddressCountry::timezones()` linked records, applies the
     * same normalization as the singular resolver (UTC-offset forms pass
     * through, other names must open as IANA zones), then collapses linked
     * zones that share one contemporary clock behavior to a single
     * representative. No IANA fallback list.
     *
     * @return list<string>
     */
    public function countryTimezones(?string $countryId): array
    {
        $resolved = app(AddressCountryResolver::class)->resolve($countryId);

        if (! $resolved instanceof AddressCountry) {
            return [];
        }

        $names = $resolved->relationLoaded('timezones')
            ? $resolved->timezones->pluck('name')->all()
            : $resolved->timezones()->orderBy('name')->pluck('name')->all();

        return $this->collapseEquivalentClockTimezones(
            $this->normalizeTimezoneNames($names),
            $this->normalizeCountryIso2($resolved->getAttribute('iso2')),
        );
    }

    public function submissionTimezoneRequired(?string $countryId): bool
    {
        return count($this->countryTimezones($countryId)) > 1;
    }

    /**
     * Non-throwing effective timezone for form hints, prefill, and preview.
     *
     * Returns the submitted choice when it is valid for the country, the
     * automatic single zone when exactly one effective option remains,
     * otherwise the app timezone as an interim hint. Never silently picks
     * the first of many.
     */
    public function previewSubmissionTimezone(?string $countryId, mixed $submittedTimezone = null): string
    {
        $timezones = $this->countryTimezones($countryId);
        $submitted = $this->normalizeSubmittedTimezone($submittedTimezone);

        if ($submitted !== null && in_array($submitted, $timezones, true)) {
            return $submitted;
        }

        if (count($timezones) === 1) {
            return $timezones[0];
        }

        return config('app.timezone', 'UTC');
    }

    /**
     * Mount/prefill default: preserved only when valid, else automatic single.
     */
    public function defaultSubmissionTimezone(?string $countryId, mixed $preservedTimezone = null): ?string
    {
        $timezones = $this->countryTimezones($countryId);
        $preserved = $this->normalizeSubmittedTimezone($preservedTimezone);

        if ($preserved !== null && in_array($preserved, $timezones, true)) {
            return $preserved;
        }

        if (count($timezones) === 1) {
            return $timezones[0];
        }

        return null;
    }

    /**
     * Strict submission timezone for the submit path.
     *
     * An explicit choice must be an effective zone of the resolved country,
     * even for single-zone countries. A missing choice is allowed only when
     * exactly one effective zone remains (automatic). Zero linked zones
     * blocks on the country instead of silently falling back.
     */
    public function resolveSubmissionTimezone(?string $countryId, mixed $submittedTimezone = null, string $validationKeyPrefix = ''): string
    {
        $timezones = $this->countryTimezones($countryId);
        $submitted = $this->normalizeSubmittedTimezone($submittedTimezone);

        if ($submitted !== null) {
            if (in_array($submitted, $timezones, true)) {
                return $submitted;
            }

            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('submission_timezone', $validationKeyPrefix) => __('The selected timezone is invalid for this country.'),
            ]);
        }

        if (count($timezones) === 1) {
            return $timezones[0];
        }

        if (count($timezones) > 1) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('submission_timezone', $validationKeyPrefix) => __('Please select the event timezone.'),
            ]);
        }

        throw ValidationException::withMessages([
            SubmissionValues::prefixedKey('submission_country_id', $validationKeyPrefix) => __('The selected country cannot accept submissions yet.'),
        ]);
    }

    /**
     * @param  iterable<mixed>  $names
     * @return list<string>
     */
    private function normalizeTimezoneNames(iterable $names): array
    {
        $valid = [];

        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }

            $normalized = $this->normalizeLinkedTimezone($name);

            if ($normalized !== null && ! in_array($normalized, $valid, true)) {
                $valid[] = $normalized;
            }
        }

        return $valid;
    }

    private function normalizeSubmittedTimezone(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeLinkedTimezone(string $timezone): ?string
    {
        $timezone = trim($timezone);

        if ($timezone === '') {
            return null;
        }

        if (preg_match('/^UTC([+-](?:0\d|1[0-4]):[0-5]\d)$/', $timezone, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $timezone) === 1) {
            return $timezone;
        }

        return @timezone_open($timezone) !== false ? $timezone : null;
    }

    /**
     * Collapse linked zones with identical contemporary clock behavior.
     *
     * Compares the UTC-offset timeline from the start of today (UTC) through
     * the next ten years. Historical differences never keep a selector
     * visible; only divergence inside this bounded window requires an
     * explicit choice. When every zone behaves identically, returns the
     * preferred representative for the country (when linked) or the first
     * zone in stable canonical order. Any divergence keeps the full list.
     *
     * @param  list<string>  $timezones
     * @return list<string>
     */
    private function collapseEquivalentClockTimezones(array $timezones, ?string $iso2): array
    {
        if (count($timezones) < 2) {
            return $timezones;
        }

        $start = Carbon::now('UTC')->startOfDay();
        $end = $start->copy()->addYears(self::EQUIVALENT_CLOCK_COMPARISON_YEARS);
        $baseline = $this->contemporaryOffsetSignature($timezones[0], $start->getTimestamp(), $end->getTimestamp());

        foreach (array_slice($timezones, 1) as $timezone) {
            if ($this->contemporaryOffsetSignature($timezone, $start->getTimestamp(), $end->getTimestamp()) !== $baseline) {
                return $timezones;
            }
        }

        $preferred = $iso2 !== null
            ? (self::PREFERRED_SUBMISSION_TIMEZONES[$iso2] ?? null)
            : null;

        if ($preferred !== null && in_array($preferred, $timezones, true)) {
            return [$preferred];
        }

        return [$timezones[0]];
    }

    /**
     * UTC-offset timeline signature for the bounded contemporary window.
     *
     * Normalized to the initial offset plus actual offset-changing
     * timestamp/offset tuples. Transitions that only change the abbreviation
     * or DST label without changing the UTC offset are ignored, so matching
     * the current offset alone is never enough to collapse, while
     * label-only churn can never force a selector.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function contemporaryOffsetSignature(string $timezone, int $start, int $end): array
    {
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($start, $end);

        if ($transitions === false || $transitions === []) {
            return [[0, $zone->getOffset(new DateTimeImmutable('@'.$start))]];
        }

        $signature = [];
        $lastOffset = null;

        foreach ($transitions as $index => $transition) {
            $offset = (int) $transition['offset'];

            if ($index === 0) {
                $signature[] = [0, $offset];
                $lastOffset = $offset;

                continue;
            }

            if ($offset === $lastOffset) {
                continue;
            }

            $signature[] = [(int) $transition['ts'], $offset];
            $lastOffset = $offset;
        }

        return $signature;
    }

    private function normalizeCountryIso2(mixed $iso2): ?string
    {
        if (! is_string($iso2)) {
            return null;
        }

        $iso2 = strtoupper(trim($iso2));

        return $iso2 !== '' ? $iso2 : null;
    }

    private function resolveLocalClockTime(Carbon $date, int $hour, int $minute, string $field, string $validationKeyPrefix): Carbon
    {
        $resolved = $date->copy()->setTime($hour, $minute);
        $expected = sprintf('%s %02d:%02d', $date->format('Y-m-d'), $hour, $minute);

        if ($resolved->format('Y-m-d H:i') !== $expected) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey($field, $validationKeyPrefix) => __('Masa yang dimasukkan tidak sah. Sila gunakan format jam:minit.'),
            ]);
        }

        return $resolved;
    }
}
