<?php

declare(strict_types=1);

namespace App\Features\Prayer\Api;

final readonly class PrayerLocationData
{
    /** @param list<string> $districtCandidates */
    public function __construct(
        public ?string $countryCode,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $stateCode,
        public array $districtCandidates,
    ) {}
}
