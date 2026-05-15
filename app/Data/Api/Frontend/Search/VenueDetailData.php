<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Venue;
use Spatie\LaravelData\Data;

class VenueDetailData extends Data
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
        public bool $is_active,
        public array $media,
        public array $contacts,
        public array $social_media,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $socialMedia
     */
    public static function fromModel(Venue $venue, array $contacts, array $socialMedia): self
    {
        return new self(
            id: (string) $venue->id,
            slug: (string) $venue->slug,
            name: (string) $venue->name,
            description: $venue->description,
            status: (string) $venue->status,
            is_active: (bool) $venue->is_active,
            media: VenueDetailMediaData::fromModel($venue)->toArray(),
            contacts: $contacts,
            social_media: $socialMedia,
        );
    }
}
