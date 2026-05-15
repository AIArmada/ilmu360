<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Institution;
use Spatie\LaravelData\Data;

class SpeakerInstitutionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $display_name,
        public string $slug,
        public ?string $position,
        public bool $is_primary,
        public string $public_image_url,
        public string $logo_url,
        public ?string $cover_url,
    ) {}

    /**
     * @param  array{public_image_url: string, logo_url: string, cover_url: ?string}  $media
     */
    public static function fromModel(Institution $institution, array $media): self
    {
        $position = data_get($institution, 'pivot.position');
        $isPrimary = data_get($institution, 'pivot.is_primary');
        $publicImageUrl = (string) $media['public_image_url'];

        return new self(
            id: (string) $institution->id,
            name: (string) $institution->name,
            display_name: (string) $institution->display_name,
            slug: (string) $institution->slug,
            position: is_string($position) && $position !== '' ? $position : null,
            is_primary: (bool) $isPrimary,
            public_image_url: $publicImageUrl,
            logo_url: (string) $media['logo_url'],
            cover_url: $media['cover_url'],
        );
    }
}
