<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Speaker;
use App\Models\User;
use Spatie\LaravelData\Data;

class SpeakerListData extends Data
{
    /**
     * @param  array{id: int, name: string, iso2: string, key: ?string}|null  $country
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $gender,
        public string $formatted_name,
        public string $status,
        public bool $is_active,
        public int $events_count,
        public string $avatar_url,
        public ?array $country,
        public bool $is_following,
    ) {}

    public static function fromModel(Speaker $speaker, ?User $user = null): self
    {
        $attributes = $speaker->getAttributes();
        $isFollowing = array_key_exists('is_following', $attributes)
            ? (bool) $attributes['is_following']
            : ($user?->isFollowing($speaker) ?? false);

        return new self(
            id: (string) $speaker->id,
            slug: (string) $speaker->slug,
            name: (string) $speaker->name,
            gender: filled($speaker->gender) ? (string) $speaker->gender : null,
            formatted_name: (string) $speaker->formatted_name,
            status: (string) $speaker->status,
            is_active: (bool) $speaker->is_active,
            events_count: (int) ($speaker->events_count ?? 0),
            avatar_url: (string) $speaker->public_avatar_url,
            country: CountryData::fromAddress($speaker->addressModel)?->toArray(),
            is_following: $isFollowing,
        );
    }
}
