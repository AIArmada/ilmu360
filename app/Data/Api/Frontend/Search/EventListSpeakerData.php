<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Speaker;
use Spatie\LaravelData\Data;

class EventListSpeakerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $gender,
        public string $formatted_name,
        public string $slug,
        public string $avatar_url,
    ) {}

    public static function fromModel(Speaker $speaker): self
    {
        return new self(
            id: (string) $speaker->id,
            name: (string) $speaker->name,
            gender: filled($speaker->gender) ? (string) $speaker->gender : null,
            formatted_name: (string) $speaker->formatted_name,
            slug: (string) $speaker->slug,
            avatar_url: (string) $speaker->public_avatar_url,
        );
    }
}
