<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Person;
use App\Models\User;
use Spatie\LaravelData\Data;

class PersonListData extends Data
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
        public ?string $verified_by,
        public int $events_count,
        public string $avatar_url,
        public ?array $country,
        public bool $is_following,
    ) {}

    public static function fromModel(Person $person, ?User $user = null): self
    {
        $attributes = $person->getAttributes();
        $isFollowing = array_key_exists('is_following', $attributes)
            ? (bool) $attributes['is_following']
            : ($user?->isFollowing($person) ?? false);

        return new self(
            id: (string) $person->id,
            slug: (string) $person->slug,
            name: (string) $person->name,
            gender: filled($person->gender) ? (string) $person->gender : null,
            formatted_name: (string) $person->formatted_name,
            status: (string) $person->status,
            verified_by: $person->getAttribute('verified_by'),
            events_count: (int) ($person->events_count ?? 0),
            avatar_url: (string) $person->public_avatar_url,
            country: CountryData::fromAddress($person->primaryAddress())?->toArray(),
            is_following: $isFollowing,
        );
    }
}
