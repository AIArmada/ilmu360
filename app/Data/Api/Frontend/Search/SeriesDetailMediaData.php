<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Series;
use Spatie\LaravelData\Data;

class SeriesDetailMediaData extends Data
{
    public function __construct(
        public string $cover_url,
    ) {}

    public static function fromModel(Series $series): self
    {
        return new self(
            cover_url: $series->getFirstMediaUrl('cover', 'thumb') ?: $series->getFirstMediaUrl('cover'),
        );
    }
}
