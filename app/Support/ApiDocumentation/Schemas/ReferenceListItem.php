<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-type ReferenceListItemArray array{id: string, slug: string, title: string, display_title: string, author: ?string, type: ?string, parent_reference_id: ?string, part_type: ?string, part_number: ?string, part_label: ?string, is_part: bool, publisher: ?string, publication_year: ?string, is_active: bool, events_count: int, front_cover_url: ?string, is_following: bool}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('ReferenceListItem')]
final readonly class ReferenceListItem implements Arrayable
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        public string $display_title,
        public ?string $author,
        public ?string $type,
        public ?string $parent_reference_id,
        public ?string $part_type,
        public ?string $part_number,
        public ?string $part_label,
        public bool $is_part,
        public ?string $publisher,
        public ?string $publication_year,
        public bool $is_active,
        public int $events_count,
        public ?string $front_cover_url,
        public bool $is_following,
    ) {}

    /** @return ReferenceListItemArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'display_title' => $this->display_title,
            'author' => $this->author,
            'type' => $this->type,
            'parent_reference_id' => $this->parent_reference_id,
            'part_type' => $this->part_type,
            'part_number' => $this->part_number,
            'part_label' => $this->part_label,
            'is_part' => $this->is_part,
            'publisher' => $this->publisher,
            'publication_year' => $this->publication_year,
            'is_active' => $this->is_active,
            'events_count' => $this->events_count,
            'front_cover_url' => $this->front_cover_url,
            'is_following' => $this->is_following,
        ];
    }
}
