<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Venue;
use Spatie\LaravelData\Data;

class VenueDetailMediaData extends Data
{
    public function __construct(
        public string $main_url,
        public string $cover_url,
    ) {}

    public static function fromModel(Venue $venue): self
    {
        return new self(
            main_url: $venue->getFirstMediaUrl('main', 'banner') ?: $venue->getFirstMediaUrl('main'),
            cover_url: $venue->getFirstMediaUrl('cover', 'banner') ?: $venue->getFirstMediaUrl('cover'),
        );
    }
}
