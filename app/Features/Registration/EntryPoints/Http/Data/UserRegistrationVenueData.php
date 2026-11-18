<?php

namespace App\Features\Registration\EntryPoints\Http\Data;

use App\Features\Places\Models\Venue;
use Spatie\LaravelData\Data;

class UserRegistrationVenueData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    public static function fromModel(Venue $venue): self
    {
        return new self(
            id: (string) $venue->id,
            name: (string) $venue->name,
        );
    }
}
