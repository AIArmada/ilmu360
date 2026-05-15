<?php

namespace App\Support\Timezone;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UserDateTimeFormatter
{
    public static function resolveTimezone(?Request $request = null): string
    {
        return UserTimezoneResolver::resolve($request);
    }

    public static function format(
        CarbonInterface|string|null $dateTime,
        string $format = 'h:i A',
        ?Request $request = null
    ): string {
        if (! $dateTime instanceof CarbonInterface) {
            return '';
        }

        return $dateTime->copy()->timezone(self::resolveTimezone($request))->format($format);
    }

    public static function translatedFormat(
        CarbonInterface|string|null $dateTime,
        string $format = 'l, j F Y',
        ?Request $request = null
    ): string {
        if (! $dateTime instanceof CarbonInterface) {
            return '';
        }

        return $dateTime->copy()->timezone(self::resolveTimezone($request))->translatedFormat($format);
    }

    public static function userNow(?Request $request = null): CarbonInterface
    {
        return now(self::resolveTimezone($request));
    }

    public static function parseUserDateToUtc(
        mixed $value,
        bool $endOfDay = false,
        ?Request $request = null
    ): ?CarbonInterface {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($value, self::resolveTimezone($request));
        } catch (\Throwable) {
            return null;
        }

        return ($endOfDay ? $parsed->endOfDay() : $parsed->startOfDay())->utc();
    }
}
