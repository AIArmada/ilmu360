<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Reference;
use App\Models\User;
use App\Support\Cache\SelectionCatalogCache;
use Spatie\LaravelData\Data;

class ReferenceDetailData extends Data
{
    /**
     * @param  list<array{id: string, name: string, slug: string}>  $authors
     * @param  list<string>  $author_ids
     * @param  array{front_cover_url: string, back_cover_url: string}  $media
     * @param  list<array<string, mixed>>  $social_media
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
        public int|string|null $publication_year,
        public ?string $description,
        public string $status,
        public ?string $verified_by,
        public bool $is_following,
        public array $media,
        public array $social_media,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $socialMedia
     */
    public static function fromModel(Reference $reference, ?User $user, array $socialMedia): self
    {
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
            description: $reference->descriptionValue(),
            status: (string) $reference->status,
            verified_by: $reference->getAttribute('verified_by'),
            is_following: $user?->isFollowing($reference) ?? false,
            media: ReferenceDetailMediaData::fromModel($reference)->toArray(),
            social_media: $socialMedia,
        );
    }
}
