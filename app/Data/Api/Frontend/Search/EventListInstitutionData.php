<?php

namespace App\Data\Api\Frontend\Search;

use App\Enums\InstitutionType;
use App\Models\Institution;
use Spatie\LaravelData\Data;

class EventListInstitutionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $type,
        public string $slug,
        public string $display_name,
        public string $public_image_url,
        public string $logo_url,
    ) {}

    public static function fromModel(Institution $institution): self
    {
        $type = $institution->type;
        $institutionType = $type instanceof InstitutionType
            ? $type->value
            : (is_string($type) ? $type : null);

        return new self(
            id: (string) $institution->id,
            name: (string) $institution->name,
            type: $institutionType,
            slug: (string) $institution->slug,
            display_name: (string) $institution->display_name,
            public_image_url: (string) $institution->public_image_url,
            logo_url: $institution->getFirstMediaUrl('logo', 'thumb') ?: $institution->getFirstMediaUrl('logo'),
        );
    }
}
