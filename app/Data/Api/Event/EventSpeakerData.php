<?php

namespace App\Data\Api\Event;

use App\Models\Speaker;
use Spatie\LaravelData\Data;

class EventSpeakerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $formatted_name,
        public string $slug,
        public string $avatar_url,
    ) {}

    public static function fromModel(Speaker $speaker): self
    {
        return new self(
            id: (string) $speaker->id,
            name: (string) $speaker->name,
            formatted_name: (string) $speaker->formatted_name,
            slug: (string) $speaker->slug,
            avatar_url: (string) $speaker->public_avatar_url,
        );
    }
}
