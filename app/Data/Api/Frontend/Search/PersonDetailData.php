<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Person;
use App\Models\User;
use Spatie\LaravelData\Data;

class PersonDetailData extends Data
{
    /**
     * @param  array<string, mixed>|string|null  $bio
     * @param  array{country_id: ?string, state_id: ?string, city_id: ?string, area_assignments: array<string, string>}|null  $address
     * @param  array{id: string, name: string, iso2: string, key: ?string}|null  $country
     * @param  array{avatar_url: string, cover_url: string, share_image_url: string}  $media
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
     * @param  array{country_id: ?string, state_id: ?string, city_id: ?string, area_assignments: array<string, string>}|null  $address
     * @param  array{id: string, name: string, iso2: string, key: ?string}|null  $country
     * @param  array<string, string>  $media
     * @param  list<array<string, string>>  $gallery
     * @param  list<array<string, mixed>>  $institutions
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $socialMedia
     */
    public static function fromModel(
        Person $person,
        ?User $user,
        ?array $address,
        ?array $country,
        ?string $location,
        array $media,
        array $gallery,
        array $institutions,
        array $contacts,
        array $socialMedia,
    ): self {
        return new self(
            id: (string) $person->id,
            slug: (string) $person->slug,
            name: (string) $person->name,
            middle_name: $person->middle_name,
            family_name: $person->family_name,
            gender: $person->gender instanceof \BackedEnum ? $person->gender->value : null,
            formatted_name: (string) $person->formatted_name,
            bio: $person->bio,
            address: $address,
            country: $country,
            location: $location,
            status: (string) $person->status,
            verified_by: $person->getAttribute('verified_by'),
            is_following: $user?->isFollowing($person) ?? false,
            followers_count: $person->followersCount(),
            media: $media,
            gallery: $gallery,
            institutions: $institutions,
            contacts: $contacts,
            social_media: $socialMedia,
        );
    }
}
