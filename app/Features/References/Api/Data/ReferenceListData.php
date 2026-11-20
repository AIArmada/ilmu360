<?php

declare(strict_types=1);

namespace App\Features\References\Api\Data;

final readonly class ReferenceListData
{
    /**
     * @param  list<array{id: string, name: string, slug: string}>  $authors
     * @param  list<string>  $author_ids
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        public string $display_title,
        public array $authors,
        public array $author_ids,
        public ?string $type,
        public ?string $parent_id,
        public string $record_kind,
        public ?int $edition_number,
        public ?string $edition_label,
        public ?string $isbn,
        public ?string $language,
        public ?string $language_label,
        public ?string $url,
        public ?string $part_type,
        public ?string $part_number,
        public ?string $part_label,
        public bool $is_part,
        public ?string $publisher,
        public ?string $publication_year,
        public string $status,
        public ?string $verified_by,
        public int $events_count,
        public ?string $front_cover_url,
        public bool $is_following,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
