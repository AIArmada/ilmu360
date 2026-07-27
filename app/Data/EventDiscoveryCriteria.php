<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class EventDiscoveryCriteria
{
    /**
     * @param  array<string, mixed>  $eventFilters
     * @param  array<string, mixed>  $relationFilters
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public ?string $text,
        public bool $fuzzyEligible,
        public ?CarbonImmutable $startsAfterUtc,
        public ?CarbonImmutable $startsBeforeUtc,
        public string $sort,
        public ?string $countryId,
        public ?string $stateId,
        public ?string $cityId,
        /** @var array<string, string> */
        public array $areaAssignments,
        public array $eventFilters,
        public array $relationFilters,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $radiusKm,
        public int $page,
        public int $perPage,
        public bool $requiresDatabaseFiltering,
        public array $filters,
    ) {}
}
