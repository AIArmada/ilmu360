<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Speaker;
use Spatie\LaravelData\Data;

class SpeakerDetailMediaData extends Data
{
    public function __construct(
        public string $avatar_url,
        public string $cover_url,
        public string $share_image_url,
    ) {}

    public static function fromModel(Speaker $speaker, string $coverUrl): self
    {
        return new self(
            avatar_url: (string) $speaker->public_avatar_url,
            cover_url: $coverUrl,
            share_image_url: $speaker->hasMedia('avatar')
                ? (string) $speaker->public_avatar_url
                : ($coverUrl !== '' ? $coverUrl : (string) $speaker->default_avatar_url),
        );
    }
}
