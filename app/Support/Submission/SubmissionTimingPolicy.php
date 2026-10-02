<?php

declare(strict_types=1);

namespace App\Support\Submission;

use AIArmada\Addressing\Support\AddressCountryResolver;
use App\Enums\EventPrayerTime;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Shared submission timing policy for the public event form and its action.
 *
 * Owns the prayer-time approximation map, Ramadhan windows, and strict
 * date/time derivation so UI hints and submit validation cannot drift apart.
 * Stored datetimes are always UTC; display-timezone concerns stay with callers.
 */
final class SubmissionTimingPolicy
{
    /**
     * @return array<string, string>
     */
    public function defaultPrayerTimes(): array
    {
        return [
            EventPrayerTime::SelepasSubuh->value => '06:30',
            EventPrayerTime::SelepasZuhur->value => '13:30',
            EventPrayerTime::SebelumJumaat->value => '13:45',
            EventPrayerTime::SelepasJumaat->value => '14:00',
            EventPrayerTime::SelepasAsar->value => '17:00',
            EventPrayerTime::SebelumMaghrib->value => '19:45',
            EventPrayerTime::SelepasMaghrib->value => '20:00',
            EventPrayerTime::SelepasIsyak->value => '21:30',
            EventPrayerTime::SelepasTarawih->value => '22:30',
        ];
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
        } catch (\Throwable) {
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
    ): Carbon {
        $date = $this->parseEventDate($eventDate, $timezone, $validationKeyPrefix);
        $prayerTime = $this->resolvePrayerTime($prayerTimeValue, $validationKeyPrefix);

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
    ): void {
        if (
            in_array($prayerTime, [EventPrayerTime::SebelumJumaat, EventPrayerTime::SelepasJumaat], true)
            && ! $eventDate->isFriday()
        ) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('prayer_time', $validationKeyPrefix) => __('Pilihan waktu Jumaat hanya boleh dipilih untuk hari Jumaat.'),
            ]);
        }

        if ($prayerTime === EventPrayerTime::SelepasTarawih && ! $this->isRamadhan($eventDate, $timezone)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('prayer_time', $validationKeyPrefix) => __('Selepas Tarawih hanya boleh dipilih semasa bulan Ramadhan.'),
            ]);
        }
    }

    public function isPrayerTimeAvailable(mixed $value, mixed $eventDate, string $timezone): bool
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
        } catch (\Throwable) {
            return false;
        }

        return match ($prayerTime) {
            EventPrayerTime::SebelumJumaat,
            EventPrayerTime::SelepasJumaat => $date->isFriday(),
            EventPrayerTime::SelepasTarawih => $this->isRamadhan($date, $timezone),
            default => true,
        };
    }

    public function resolveStartTimeForComparison(mixed $prayerTimeValue, mixed $customTime): ?string
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

        return $this->defaultPrayerTimes()[$prayerTime->value] ?? null;
    }

    public function isRamadhan(Carbon $date, ?string $timezone = null): bool
    {
        $timezone ??= config('app.timezone', 'UTC');
        $year = $date->year;
        $ramadhanPeriods = [
            2026 => ['start' => '02-18', 'end' => '03-19'],
            2027 => ['start' => '02-07', 'end' => '03-08'],
            2028 => ['start' => '01-27', 'end' => '02-25'],
            2029 => ['start' => '01-16', 'end' => '02-13'],
            2030 => ['start' => '01-05', 'end' => '02-03'],
        ];

        if (! isset($ramadhanPeriods[$year])) {
            return false;
        }

        $period = $ramadhanPeriods[$year];
        $startDate = Carbon::parse("{$year}-{$period['start']}", $timezone)->startOfDay();
        $endDate = Carbon::parse("{$year}-{$period['end']}", $timezone)->endOfDay();

        return $date->between($startDate, $endDate);
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

    public function resolveSubmissionTimezone(?string $countryId): string
    {
        return app(AddressCountryResolver::class)->timezoneFor($countryId)
            ?? config('app.timezone', 'UTC');
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
