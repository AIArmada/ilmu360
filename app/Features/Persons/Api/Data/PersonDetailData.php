<?php

declare(strict_types=1);

namespace App\Features\Persons\Api\Data;

final readonly class PersonDetailData
{
    /**
     * @param  array<string, mixed>|string|null  $bio
     * @param  array{country_id: ?string, state_id: ?string, city_id: ?string, area_assignments: array<string, string>}|null  $address
     * @param  array{id: string, name: string, iso2: string, key: ?string}|null  $country
     * @param  array{avatar_url: string, main_url: string, share_image_url: string}  $media
     * @param  list<array{id: string, name: string, url: string, thumb_url: string}>  $gallery
     * @param  list<array<string, mixed>>  $institutions
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $social_media
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $middle_name,
        public ?string $family_name,
        public ?string $gender,
        public string $formatted_name,
        public array|string|null $bio,
        public ?array $address,
        public ?array $country,
        public ?string $location,
        public string $status,
        public ?string $verified_by,
        public bool $is_following,
        public int $followers_count,
        public array $media,
        public array $gallery,
        public array $institutions,
        public array $contacts,
        public array $social_media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
