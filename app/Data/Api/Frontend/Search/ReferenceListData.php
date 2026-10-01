<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Reference;
use App\Models\User;
use App\Support\Cache\SelectionCatalogCache;
use Spatie\LaravelData\Data;

class ReferenceListData extends Data
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

    public static function fromModel(Reference $reference, ?User $user = null): self
    {
        $attributes = $reference->getAttributes();
        $isFollowing = array_key_exists('is_following', $attributes)
            ? (bool) $attributes['is_following']
            : ($user?->isFollowing($reference) ?? false);
        $authors = $reference->effectiveAuthorsStructured();

        return new self(
            id: (string) $reference->id,
            slug: (string) $reference->slug,
            title: (string) $reference->titleValue(),
            display_title: $reference->displayTitle(),
            authors: $authors,
            author_ids: array_column($authors, 'id'),
            type: $reference->typeValue(),
            parent_id: $reference->parent_id,
            record_kind: (string) $reference->record_kind,
            edition_number: $reference->edition_number,
            edition_label: $reference->edition_label,
            isbn: $reference->isbn,
            language: $reference->language,
            language_label: app(SelectionCatalogCache::class)->languageLabel($reference->language),
            url: $reference->url,
            part_type: $reference->partTypeValue(),
            part_number: $reference->partNumberValue(),
            part_label: $reference->partLabelValue(),
            is_part: $reference->isPart(),
            publisher: $reference->publisherValue(),
            publication_year: filled($reference->year) ? (string) $reference->year : null,
            status: (string) $reference->status,
            verified_by: $reference->getAttribute('verified_by'),
            events_count: (int) ($reference->events_count ?? 0),
            front_cover_url: $reference->getFirstMediaUrl('front_cover', 'thumb') ?: ($reference->getFirstMediaUrl('front_cover') ?: null),
            is_following: $isFollowing,
        );
    }
}
