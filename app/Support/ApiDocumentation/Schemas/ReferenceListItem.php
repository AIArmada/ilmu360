<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-type ReferenceListItemArray array{id: string, slug: string, title: string, display_title: string, authors: list<array{id: string, name: string, slug: string}>, author_ids: list<string>, type: ?string, parent_id: ?string, record_kind: string, edition_number: ?int, edition_label: ?string, isbn: ?string, language: ?string, language_label: ?string, url: ?string, part_type: ?string, part_number: ?string, part_label: ?string, is_part: bool, publisher: ?string, publication_year: ?string, status: string, verified_by: ?string, events_count: int, front_cover_url: ?string, is_following: bool}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('ReferenceListItem')]
final readonly class ReferenceListItem implements Arrayable
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

    /** @return ReferenceListItemArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'display_title' => $this->display_title,
            'authors' => $this->authors,
            'author_ids' => $this->author_ids,
            'type' => $this->type,
            'parent_id' => $this->parent_id,
            'record_kind' => $this->record_kind,
            'edition_number' => $this->edition_number,
            'edition_label' => $this->edition_label,
            'isbn' => $this->isbn,
            'language' => $this->language,
            'language_label' => $this->language_label,
            'url' => $this->url,

            'part_type' => $this->part_type,
            'part_number' => $this->part_number,
            'part_label' => $this->part_label,
            'is_part' => $this->is_part,
            'publisher' => $this->publisher,
            'publication_year' => $this->publication_year,
            'status' => $this->status,
            'verified_by' => $this->verified_by,
            'events_count' => $this->events_count,
            'front_cover_url' => $this->front_cover_url,
            'is_following' => $this->is_following,
        ];
    }
}
