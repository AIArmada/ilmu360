<?php

namespace App\Data\Api\Frontend\Search;

use App\Enums\InstitutionType;
use App\Models\Institution;
use App\Models\User;
use App\Support\Location\AddressHierarchyFormatter;
use Spatie\LaravelData\Data;

class InstitutionListData extends Data
{
    /**
     * @param  array{id: int, name: string, iso2: string, key: ?string}|null  $country
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $type,
        public ?string $nickname,
        public string $display_name,
        public int $events_count,
        public string $public_image_url,
        public string $logo_url,
        public ?string $cover_url,
        public ?array $country,
        public ?string $location,
        public ?float $distance_km,
        public bool $is_following,
    ) {}

    public static function fromModel(Institution $institution, ?User $user = null): self
    {
        $attributes = $institution->getAttributes();
        $type = $institution->type;
        $institutionType = $type instanceof InstitutionType
            ? $type->value
            : (is_string($type) ? $type : null);
        $isFollowing = array_key_exists('is_following', $attributes)
            ? (bool) $attributes['is_following']
            : ($user?->isFollowing($institution) ?? false);
        $eventsCount = (int) ($institution->events_count ?? 0);
        $publicImageUrl = (string) $institution->public_image_url;
        $logoUrl = (string) $institution->public_logo_url;
        $coverUrl = (string) $institution->public_cover_url;
        $logoFallbackUrl = $institution->getFallbackMediaUrl('logo', 'thumb');
        $resolvedLogoUrl = $logoUrl !== ''
            ? $logoUrl
            : ($logoFallbackUrl !== '' ? $logoFallbackUrl : $publicImageUrl);
        $location = AddressHierarchyFormatter::format($institution->address);
        $distanceKm = self::distanceKm($attributes['distance_km'] ?? null);

        return new self(
            id: (string) $institution->id,
            slug: (string) $institution->slug,
            name: (string) $institution->name,
            type: $institutionType,
            nickname: $institution->nickname,
            display_name: (string) $institution->display_name,
            events_count: $eventsCount,
            public_image_url: $publicImageUrl,
            logo_url: $resolvedLogoUrl,
            cover_url: $coverUrl !== '' ? $coverUrl : null,
            country: CountryData::fromAddress($institution->address)?->toArray(),
            location: $location !== '' ? $location : null,
            distance_km: $distanceKm,
            is_following: $isFollowing,
        );
    }

    private static function distanceKm(mixed $distance): ?float
    {
        if (! is_numeric($distance)) {
            return null;
        }

        return round((float) $distance, 2);
    }
}
