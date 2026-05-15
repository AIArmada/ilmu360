<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Venue;
use Spatie\LaravelData\Data;

class EventListVenueData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
    ) {}

    public static function fromModel(Venue $venue): self
    {
        return new self(
            id: (string) $venue->id,
            name: (string) $venue->name,
            slug: (string) $venue->slug,
        );
    }
}
