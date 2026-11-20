<?php

declare(strict_types=1);

namespace App\Features\Places\Api\Data;

final readonly class VenueDetailData
{
    /**
     * @param  array{cover_url: string}  $media
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $social_media
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $description,
        public string $status,
        public ?string $verified_by,
        public string $visibility,
        public array $media,
        public array $contacts,
        public array $social_media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
