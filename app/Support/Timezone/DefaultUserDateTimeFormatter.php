<?php

declare(strict_types=1);

namespace App\Support\Timezone;

use App\Shared\Time\UserDateTimeFormatting;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

final class DefaultUserDateTimeFormatter implements UserDateTimeFormatting
{
    public function resolveTimezone(?Request $request = null): string
    {
        return UserDateTimeFormatter::resolveTimezone($request);
    }

    public function format(
        CarbonInterface|string|null $dateTime,
        string $format = 'h:i A',
        ?Request $request = null,
        ?string $timezone = null,
    ): string {
        return UserDateTimeFormatter::format($dateTime, $format, $request, $timezone);
    }

    public function translatedFormat(
        CarbonInterface|string|null $dateTime,
        string $format = 'l, j F Y',
        ?Request $request = null,
        ?string $timezone = null,
    ): string {
        return UserDateTimeFormatter::translatedFormat($dateTime, $format, $request, $timezone);
    }
}
