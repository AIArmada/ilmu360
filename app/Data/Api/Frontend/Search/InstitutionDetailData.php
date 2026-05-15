<?php

namespace App\Data\Api\Frontend\Search;

use App\Enums\InstitutionType;
use App\Models\Institution;
use App\Models\User;
use Filament\Support\Contracts\HasLabel;
use Spatie\LaravelData\Data;

class InstitutionDetailData extends Data
{
    /**
     * @param  array{country_id: ?int, state_id: ?int, district_id: ?int, subdistrict_id: ?int}|null  $address
     * @param  array{id: int, name: string, iso2: string, key: ?string}|null  $country
     * @param  array{public_image_url: string, logo_url: string, cover_url: ?string}  $media
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $social_media
     * @param  list<array<string, mixed>>  $donation_channels
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $nickname,
        public string $display_name,
        public ?string $description,
        public string $status,
        public ?string $type,
        public ?string $type_label,
        public ?string $address_line,
        public ?array $address,
        public ?array $country,
        public ?string $map_url,
        public ?float $map_lat,
        public ?float $map_lng,
        public int $followers_count,
        public int $speaker_count,
        public bool $is_following,
        public array $media,
        public array $contacts,
        public array $social_media,
        public ?string $waze_url,
        public array $donation_channels,
    ) {}

    /**
     * @param  array{country_id: ?int, state_id: ?int, district_id: ?int, subdistrict_id: ?int}|null  $address
     * @param  array{id: int, name: string, iso2: string, key: ?string}|null  $country
     * @param  array{public_image_url: string, logo_url: string, cover_url: ?string}  $media
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $socialMedia
     * @param  list<array<string, mixed>>  $donationChannels
     */
    public static function fromModel(
        Institution $institution,
        ?User $user,
        ?array $address,
        ?array $country,
        ?string $addressLine,
        array $media,
        int $speakerCount,
        array $contacts,
        array $socialMedia,
        array $donationChannels,
    ): self {
        $type = $institution->type;
        $institutionType = $type instanceof InstitutionType
            ? $type->value
            : (is_string($type) ? $type : null);

        return new self(
            id: (string) $institution->id,
            slug: (string) $institution->slug,
            name: (string) $institution->name,
            nickname: $institution->nickname,
            display_name: (string) $institution->display_name,
            description: $institution->description,
            status: (string) $institution->status,
            type: $institutionType,
            type_label: $institution->type instanceof HasLabel ? $institution->type->getLabel() : null,
            address_line: $addressLine,
            address: $address,
            country: $country,
            map_url: $institution->addressModel?->google_maps_url,
            map_lat: $institution->addressModel?->lat,
            map_lng: $institution->addressModel?->lng,
            followers_count: $institution->followersCount(),
            speaker_count: $speakerCount,
            is_following: $user?->isFollowing($institution) ?? false,
            media: InstitutionDetailMediaData::fromCardMedia($media)->toArray(),
            contacts: $contacts,
            social_media: $socialMedia,
            waze_url: $institution->addressModel?->waze_url,
            donation_channels: $donationChannels,
        );
    }
}
