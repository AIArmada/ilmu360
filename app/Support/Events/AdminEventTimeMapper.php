<?php

namespace App\Support\Events;

use App\Enums\EventPrayerTime;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            $data['end_time'] = $endsAt->format('H:i');
        }

        $data['prayer_time'] = self::resolvePrayerTimeForFill($data)->value;

        return $data;
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

        $startsAt = self::resolveStartsAt($eventDate, $prayerTime, (string) ($data['custom_time'] ?? null));
        $endsAt = self::resolveEndsAt($startsAt, (string) ($data['end_time'] ?? null), $timezone);

        if ($endsAt instanceof Carbon && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'data.end_time' => __('Masa akhir mestilah selepas masa mula.'),
            ]);
        }

        $data['starts_at'] = $startsAt;
        $data['ends_at'] = $endsAt;
        $data['timing_mode'] = $prayerTime->isCustomTime() ? TimingMode::Absolute->value : TimingMode::PrayerRelative->value;
        $data['prayer_reference'] = $prayerTime->toPrayerReference()?->value;
        $data['prayer_offset'] = $prayerTime->getDefaultOffset()?->value;
        $data['prayer_display_text'] = $prayerTime->isCustomTime() ? null : $prayerTime->getLabel();

        unset($data['event_date'], $data['prayer_time'], $data['custom_time'], $data['end_time']);

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

        if ($timingMode !== TimingMode::PrayerRelative->value || ! $reference) {
            return EventPrayerTime::LainWaktu;
        }

        return EventPrayerTime::fromPrayerTiming($reference, $offset) ?? EventPrayerTime::LainWaktu;
    }

    protected static function resolveStartsAt(Carbon $eventDate, EventPrayerTime $prayerTime, ?string $customTime): Carbon
    {
        if ($prayerTime->isCustomTime()) {
            $resolvedCustomTime = $customTime ?? '20:00';
            $time = self::parseClockTime($resolvedCustomTime);

            return $eventDate->copy()->setTime($time->hour, $time->minute)->utc();
        }

        $timeString = self::defaultPrayerTimes()[$prayerTime->value] ?? '20:00';
        $time = Carbon::parse($timeString);

        return $eventDate->copy()->setTime($time->hour, $time->minute)->utc();
    }

    protected static function resolveEndsAt(Carbon $startsAt, ?string $endTime, string $timezone): ?Carbon
    {
        if (! is_string($endTime) || $endTime === '') {
            return null;
        }

        $startInUserTimezone = $startsAt->copy()->setTimezone($timezone);
        $parts = self::parseClockTimeParts($endTime);
        $candidate = $startInUserTimezone->copy()->setTime($parts['hour'], $parts['minute']);

        if (
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

        $fallback = Carbon::parse($normalized);

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
}
