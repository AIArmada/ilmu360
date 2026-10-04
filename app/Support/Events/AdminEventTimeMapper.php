<?php

namespace App\Support\Events;

use App\Enums\EventPrayerTime;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Services\Prayer\HardcodedPrayerFallback;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminEventTimeMapper
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function injectFormTimeFields(array $data): array
    {
        $timezone = (string) ($data['timezone'] ?? 'Asia/Kuala_Lumpur');

        if (! empty($data['starts_at'])) {
            $startsAt = Carbon::parse((string) $data['starts_at'], 'UTC')->setTimezone($timezone);
            $data['event_date'] = $startsAt->toDateString();
            $data['custom_time'] = $startsAt->format('H:i');
        }

        if (! empty($data['ends_at'])) {
            $endsAt = Carbon::parse((string) $data['ends_at'], 'UTC')->setTimezone($timezone);
            $data['end_date'] = $endsAt->toDateString();
            $data['end_time'] = $endsAt->format('H:i');
        }

        $data['prayer_time'] = self::resolvePrayerTimeForFill($data)->value;

        // Offsets can roll the start past midnight; the form must show the
        // original prayer day (whose times were resolved), not the rolled
        // start day, so later edits re-resolve the same anchor.
        $prayerDate = self::normalizePrayerDateString($data['prayer_date'] ?? null);

        if ($prayerDate !== null && $data['prayer_time'] !== EventPrayerTime::LainWaktu->value) {
            $data['event_date'] = $prayerDate;
        }

        return $data;
    }

    public static function normalizePrayerDateString(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches) !== 1) {
            return null;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $matches[0] : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeForPersistence(array $data): array
    {
        $timezone = (string) ($data['timezone'] ?? 'Asia/Kuala_Lumpur');

        $eventDate = self::parseEventDate((string) $data['event_date'], $timezone)->startOfDay();
        $prayerTime = EventPrayerTime::tryFrom((string) ($data['prayer_time'] ?? '')) ?? EventPrayerTime::LainWaktu;

        $resolvedClock = is_string($data['resolved_start_clock'] ?? null) && $data['resolved_start_clock'] !== ''
            ? $data['resolved_start_clock']
            : null;
        $resolvedDate = is_string($data['resolved_start_date'] ?? null) && $data['resolved_start_date'] !== ''
            ? $data['resolved_start_date']
            : null;
        $resolvedInstant = is_string($data['resolved_start_instant'] ?? null) && $data['resolved_start_instant'] !== ''
            ? $data['resolved_start_instant']
            : null;

        $startsAt = self::resolveStartsAt($eventDate, $prayerTime, (string) ($data['custom_time'] ?? null), $resolvedClock, $resolvedDate, $resolvedInstant);
        $endsAt = self::resolveEndsAt(
            $startsAt,
            (string) ($data['end_time'] ?? null),
            $timezone,
            isset($data['end_date']) ? (string) $data['end_date'] : null,
        );

        if ($endsAt instanceof Carbon && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'data.end_time' => __('Masa akhir mestilah selepas masa mula.'),
            ]);
        }

        $data['starts_at'] = $startsAt;
        $data['ends_at'] = $endsAt;
        $data['timing_mode'] = $prayerTime->isCustomTime() ? TimingMode::Absolute->value : TimingMode::PrayerRelative->value;
        // Tarawih is label-only: a null anchor like frontend storage.
        $data['prayer_reference'] = $prayerTime === EventPrayerTime::SelepasTarawih
            ? null
            : $prayerTime->toPrayerReference()?->value;
        $data['prayer_offset'] = $prayerTime->getDefaultOffset()?->value;
        $data['prayer_display_text'] = $prayerTime->isCustomTime() ? null : $prayerTime->getLabel();
        $data['prayer_source'] = $resolvedClock !== null && is_string($data['prayer_source'] ?? null)
            ? $data['prayer_source']
            : null;
        $data['prayer_fetched_at'] = $resolvedClock !== null && is_string($data['prayer_fetched_at'] ?? null)
            ? $data['prayer_fetched_at']
            : null;
        $data['prayer_zone'] = $resolvedClock !== null && is_string($data['prayer_zone'] ?? null)
            ? $data['prayer_zone']
            : null;
        $data['prayer_lat'] = $resolvedClock !== null && isset($data['prayer_lat']) && is_numeric($data['prayer_lat'])
            ? (float) $data['prayer_lat']
            : null;
        $data['prayer_lng'] = $resolvedClock !== null && isset($data['prayer_lng']) && is_numeric($data['prayer_lng'])
            ? (float) $data['prayer_lng']
            : null;

        unset($data['event_date'], $data['prayer_time'], $data['custom_time'], $data['end_time']);
        unset($data['end_date'], $data['resolved_start_clock'], $data['resolved_start_date'], $data['resolved_start_instant']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function resolvePrayerTimeForFill(array $data): EventPrayerTime
    {
        $timingMode = (string) ($data['timing_mode'] ?? TimingMode::Absolute->value);
        $reference = PrayerReference::tryFrom((string) ($data['prayer_reference'] ?? ''));
        $offset = PrayerOffset::tryFrom((string) ($data['prayer_offset'] ?? ''));

        if ($timingMode !== TimingMode::PrayerRelative->value) {
            return EventPrayerTime::LainWaktu;
        }

        // Only Tarawih stores a null anchor (label-only).
        if (! $reference) {
            return EventPrayerTime::SelepasTarawih;
        }

        return EventPrayerTime::fromPrayerTiming($reference, $offset) ?? EventPrayerTime::LainWaktu;
    }

    protected static function resolveStartsAt(Carbon $eventDate, EventPrayerTime $prayerTime, ?string $customTime, ?string $resolvedClock = null, ?string $resolvedDate = null, ?string $resolvedInstant = null): Carbon
    {
        if ($prayerTime->isCustomTime()) {
            $resolvedCustomTime = $customTime ?? HardcodedPrayerFallback::DEFAULT_CLOCK;
            $time = self::parseClockTime($resolvedCustomTime);

            return $eventDate->copy()->setTime($time->hour, $time->minute)->utc();
        }

        // The resolved UTC instant stores directly: rebuilding it from wall
        // text would reintroduce DST-fold ambiguity (second occurrence
        // reads back as the first). A corrupt instant falls through to the
        // clock path, never breaking persistence.
        if ($resolvedInstant !== null) {
            try {
                return Carbon::parse($resolvedInstant, 'UTC')->utc();
            } catch (Throwable) {
                // Fall through to the clock path below.
            }
        }

        $timeString = $resolvedClock ?? self::defaultPrayerTimes()[$prayerTime->value] ?? HardcodedPrayerFallback::DEFAULT_CLOCK;

        try {
            $time = self::parseClockTime($timeString);
            // Provider offsets can roll past midnight; the resolved local
            // date travels with the clock.
            $day = $resolvedDate !== null ? self::parseResolvedDate($resolvedDate, $eventDate->getTimezone()) : $eventDate;
        } catch (ValidationException) {
            // A corrupt provider clock degrades to the label's
            // hardcoded estimate; our data must never break persistence.
            $time = self::parseClockTime(self::defaultPrayerTimes()[$prayerTime->value] ?? HardcodedPrayerFallback::DEFAULT_CLOCK);
            $day = $eventDate;
        }

        return $day->copy()->setTime($time->hour, $time->minute)->utc();
    }

    protected static function parseResolvedDate(string $resolvedDate, DateTimeZone $timezone): Carbon
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($resolvedDate), $matches) !== 1 || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw ValidationException::withMessages([
                'data.prayer_time' => __('Masa yang dimasukkan tidak sah.'),
            ]);
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $matches[0], $timezone)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'data.prayer_time' => __('Masa yang dimasukkan tidak sah.'),
            ]);
        }
    }

    protected static function resolveEndsAt(
        Carbon $startsAt,
        ?string $endTime,
        string $timezone,
        ?string $endDate = null,
    ): ?Carbon {
        if (! is_string($endTime) || $endTime === '') {
            return null;
        }

        $startInUserTimezone = $startsAt->copy()->setTimezone($timezone);
        $parts = self::parseClockTimeParts($endTime);
        $candidate = $startInUserTimezone->copy();

        if ($endDate !== null && $endDate !== $startInUserTimezone->toDateString()) {
            $candidate = self::parseEventDate($endDate, $timezone);
        }

        $candidate->setTime($parts['hour'], $parts['minute']);

        if (
            $endDate === null
            &&
            $candidate->lessThanOrEqualTo($startInUserTimezone)
            && $parts['meridiem'] === null
            && $parts['hour'] < 12
            && $startInUserTimezone->hour >= 12
        ) {
            $candidate = $candidate->addHours(12);
        }

        return $candidate->utc();
    }

    protected static function parseEventDate(string $date, string $timezone): Carbon
    {
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date) === 1) {
            return Carbon::createFromFormat('d/m/Y', $date, $timezone);
        }

        return Carbon::parse($date, $timezone);
    }

    /**
     * Normalizes an admin date input (Y-m-d or localized d/m/Y) to Y-m-d,
     * or null when unparseable. Provider resolution must go through here:
     * PrayerQuery accepts Y-m-d only.
     */
    public static function normalizeEventDateString(mixed $date, string $timezone): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return self::parseEventDate(trim($date), $timezone)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    protected static function parseClockTime(string $time): Carbon
    {
        $parts = self::parseClockTimeParts($time);

        return Carbon::createFromTime($parts['hour'], $parts['minute']);
    }

    /**
     * @return array{hour: int, minute: int, meridiem: 'am'|'pm'|null}
     */
    protected static function parseClockTimeParts(string $time): array
    {
        $normalized = Str::of($time)
            ->trim()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->toString();

        if (preg_match('/^(\d{1,2})[:.](\d{2})(?::\d{2})?\s*([\p{L}.]+)?$/u', $normalized, $matches) === 1) {
            $rawHour = (int) $matches[1];
            $minute = (int) $matches[2];
            $suffix = isset($matches[3]) ? str_replace('.', '', $matches[3]) : '';
            $meridiem = self::normalizeMeridiem($suffix);

            if ($minute < 0 || $minute > 59) {
                throw ValidationException::withMessages([
                    'data.end_time' => __('Format masa akhir tidak sah.'),
                ]);
            }

            if ($meridiem !== null) {
                if ($rawHour < 1 || $rawHour > 12) {
                    throw ValidationException::withMessages([
                        'data.end_time' => __('Format masa akhir tidak sah.'),
                    ]);
                }

                $hour = $rawHour % 12;

                if ($meridiem === 'pm') {
                    $hour += 12;
                }

                return [
                    'hour' => $hour,
                    'minute' => $minute,
                    'meridiem' => $meridiem,
                ];
            }

            if ($rawHour < 0 || $rawHour > 23) {
                throw ValidationException::withMessages([
                    'data.end_time' => __('Format masa akhir tidak sah.'),
                ]);
            }

            return [
                'hour' => $rawHour,
                'minute' => $minute,
                'meridiem' => null,
            ];
        }

        try {
            $fallback = Carbon::parse($normalized);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'data.end_time' => __('Format masa akhir tidak sah.'),
            ]);
        }

        return [
            'hour' => $fallback->hour,
            'minute' => $fallback->minute,
            'meridiem' => self::normalizeMeridiemFromRaw($normalized),
        ];
    }

    protected static function normalizeMeridiemFromRaw(string $value): ?string
    {
        if (preg_match('/\b(am|a\.m\.?|pagi)\b/u', $value) === 1) {
            return 'am';
        }

        if (preg_match('/\b(pm|p\.m\.?|ptg|petang|malam|tengahari|tgh\s*hari)\b/u', $value) === 1) {
            return 'pm';
        }

        return null;
    }

    protected static function normalizeMeridiem(string $suffix): ?string
    {
        if ($suffix === '') {
            return null;
        }

        if (in_array($suffix, ['am', 'a', 'pagi'], true)) {
            return 'am';
        }

        if (in_array($suffix, ['pm', 'p', 'ptg', 'petang', 'malam', 'tengahari', 'tghhari'], true)) {
            return 'pm';
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected static function defaultPrayerTimes(): array
    {
        return HardcodedPrayerFallback::MAP;
    }
}
