<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Speaker;
use App\Models\User;
use Spatie\LaravelData\Data;

class SpeakerDetailData extends Data
{
    /**
     * @param  array<string, mixed>|string|null  $bio
     * @param  list<array<string, mixed>>  $qualifications
     * @param  array{country_id: ?int, state_id: ?int, district_id: ?int, subdistrict_id: ?int}|null  $address
     * @param  array{id: int, name: string, iso2: string, key: ?string}|null  $country
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
        public ?string $gender,
        public string $formatted_name,
        public ?string $job_title,
        public bool $is_freelance,
        public array|string|null $bio,
        public array $qualifications,
        public ?array $address,
        public ?array $country,
        public ?string $location,
        public string $status,
        public bool $is_active,
        public bool $is_following,
        public int $followers_count,
        public array $media,
        public array $gallery,
        public array $institutions,
        public array $contacts,
        public array $social_media,
    ) {}

    /**
     * @param  array{country_id: ?int, state_id: ?int, district_id: ?int, subdistrict_id: ?int}|null  $address
     * @param  array{id: int, name: string, iso2: string, key: ?string}|null  $country
     * @param  array<string, string>  $media
     * @param  list<array<string, string>>  $gallery
     * @param  list<array<string, mixed>>  $institutions
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $socialMedia
     */
    public static function fromModel(
        Speaker $speaker,
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
            id: (string) $speaker->id,
            slug: (string) $speaker->slug,
            name: (string) $speaker->name,
            gender: filled($speaker->gender) ? (string) $speaker->gender : null,
            formatted_name: (string) $speaker->formatted_name,
            job_title: $speaker->job_title,
            is_freelance: (bool) $speaker->is_freelance,
            bio: $speaker->bio,
            qualifications: is_array($speaker->qualifications) ? array_values($speaker->qualifications) : [],
            address: $address,
            country: $country,
            location: $location,
            status: (string) $speaker->status,
            is_active: (bool) $speaker->is_active,
            is_following: $user?->isFollowing($speaker) ?? false,
            followers_count: $speaker->followersCount(),
            media: $media,
            gallery: $gallery,
            institutions: $institutions,
            contacts: $contacts,
            social_media: $socialMedia,
        );
    }
}
