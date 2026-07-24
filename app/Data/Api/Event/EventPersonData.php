<?php

namespace App\Data\Api\Event;

use App\Models\Person;
use Spatie\LaravelData\Data;

class EventPersonData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $formatted_name,
        public string $slug,
        public string $avatar_url,
    ) {}

    public static function fromModel(Person $person): self
    {
        return new self(
            id: (string) $person->id,
            name: (string) $person->name,
            formatted_name: (string) $person->formatted_name,
            slug: (string) $person->slug,
            avatar_url: (string) $person->public_avatar_url,
        );
    }
}
