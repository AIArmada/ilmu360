<?php

declare(strict_types=1);

namespace App\Features\Prayer\Api;

interface ResolvePrayerAnchorAction
{
    /**
     * @return array{clock: string, instant: string, source: string, fetched_at: string, stale: bool}|null
     */
    public function handle(PrayerQuery $query, PrayerReference $reference, bool $allowLive = false): ?array;
}
