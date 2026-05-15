<?php

namespace App\Data\Api\UserRegistration;

use App\Models\Institution;
use Spatie\LaravelData\Data;

class UserRegistrationInstitutionData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
    ) {}

    public static function fromModel(Institution $institution): self
    {
        return new self(
            id: (string) $institution->id,
            name: (string) $institution->name,
            slug: (string) $institution->slug,
        );
    }
}
