<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Api;

use Carbon\CarbonImmutable;

final readonly class SavedSearchSnapshot
{
    /**
     * @param  string|array<string, mixed>|null  $query
     * @param  array<string, mixed>|null  $filters
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $userType,
        public string $userId,
        public string $name,
        public string|array|null $query,
        public ?array $filters,
        public ?string $notify,
        public ?float $lat,
        public ?float $lng,
        public ?int $radiusKm,
        public ?CarbonImmutable $createdAt,
        public array $payload = [],
        public ?CarbonImmutable $updatedAt = null,
    ) {}
}
