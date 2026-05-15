<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Series;
use App\Models\User;
use Spatie\LaravelData\Data;

class SeriesDetailData extends Data
{
    /**
     * @param  array{cover_url: string}  $media
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        public ?string $description,
        public string $visibility,
        public bool $is_following,
        public array $media,
    ) {}

    public static function fromModel(Series $series, ?User $user): self
    {
        return new self(
            id: (string) $series->id,
            slug: (string) $series->slug,
            title: (string) $series->title,
            description: $series->description,
            visibility: (string) $series->visibility,
            is_following: $user?->isFollowing($series) ?? false,
            media: SeriesDetailMediaData::fromModel($series)->toArray(),
        );
    }
}
