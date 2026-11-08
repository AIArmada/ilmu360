<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;

interface UserDateTimeFormatting
{
    public function resolveTimezone(?Request $request = null): string;

    public function format(
        CarbonInterface|string|null $dateTime,
        string $format = 'h:i A',
        ?Request $request = null,
        ?string $timezone = null,
    ): string;

    public function translatedFormat(
        CarbonInterface|string|null $dateTime,
        string $format = 'l, j F Y',
        ?Request $request = null,
        ?string $timezone = null,
    ): string;
}
