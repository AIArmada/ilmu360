<?php

declare(strict_types=1);

namespace App\Features\Persons\Api\Data;

final readonly class PersonInstitutionData
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
