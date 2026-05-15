<?php

namespace App\Data\Api\Frontend\Search;

use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SpeakerGalleryItemData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $thumb_url,
    ) {}

    public static function fromModel(Media $media): self
    {
        return new self(
            id: (string) $media->getKey(),
            name: $media->name,
            url: $media->getUrl(),
            thumb_url: $media->getAvailableUrl(['gallery_thumb']) ?: $media->getUrl(),
        );
    }
}
