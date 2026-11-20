<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api\Data;

final readonly class InstitutionListData
{
    /**
     * @param  array{id: int, name: string, iso2: string, key: ?string}|null  $country
     * @param  list<array{name_type: string, full_name: string, language_code: string, is_primary: bool}>  $names
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $type,
        public ?string $primary_nickname,
        public array $names,
        public string $display_name,
        public int $events_count,
        public string $public_image_url,
        public string $logo_url,
        public ?string $cover_url,
        public ?array $country,
        public ?string $location,
        public ?float $distance_km,
        public bool $is_following,
        public ?string $verified_by = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
