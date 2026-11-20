<?php

declare(strict_types=1);

namespace App\Features\Persons\Api\Data;

final readonly class PersonListData
{
    /**
     * @param  array{id: int, name: string, iso2: string, key: ?string}|null  $country
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $middle_name,
        public ?string $family_name,
        public ?string $gender,
        public string $formatted_name,
        public string $status,
        public ?string $verified_by,
        public int $events_count,
        public string $avatar_url,
        public ?array $country,
        public bool $is_following,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
