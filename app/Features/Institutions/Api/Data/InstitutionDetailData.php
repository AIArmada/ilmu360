<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api\Data;

final readonly class InstitutionDetailData
{
    /**
     * @param  array{country_id: ?string, state_id: ?string, city_id: ?string, area_assignments: array<string, string>}|null  $address
     * @param  array{id: string, name: string, iso2: string, key: ?string}|null  $country
     * @param  array{public_image_url: string, logo_url: string, cover_url: ?string}  $media
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $social_media
     * @param  list<array<string, mixed>>  $donation_channels
     * @param  list<array{name_type: string, full_name: string, language_code: string, is_primary: bool}>  $names
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $primary_nickname,
        public array $names,
        public string $display_name,
        public ?string $description,
        public string $status,
        public ?string $verified_by,
        public ?string $type,
        public ?string $type_label,
        public ?string $address_line,
        public ?array $address,
        public ?array $country,
        public ?string $map_url,
        public ?float $map_lat,
        public ?float $map_lng,
        public int $followers_count,
        public int $person_count,
        public bool $is_following,
        public array $media,
        public array $contacts,
        public array $social_media,
        public ?string $waze_url,
        public array $donation_channels,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
