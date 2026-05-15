<?php

namespace App\Data\Api\Frontend\Search;

use Spatie\LaravelData\Data;

class InstitutionDetailMediaData extends Data
{
    public function __construct(
        public string $public_image_url,
        public string $logo_url,
        public ?string $cover_url,
    ) {}

    /** @param  array{public_image_url: string, logo_url: string, cover_url: ?string}  $media */
    public static function fromCardMedia(array $media): self
    {
        return new self(
            public_image_url: (string) $media['public_image_url'],
            logo_url: (string) $media['logo_url'],
            cover_url: $media['cover_url'],
        );
    }
}
