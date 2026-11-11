<?php

declare(strict_types=1);

namespace App\Shared\Prayer;

use Carbon\CarbonInterface;

interface RamadanGate
{
    public function isRamadan(CarbonInterface|string $date, ?string $timezone = null, ?string $countryCode = null): bool;
}
