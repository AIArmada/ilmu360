<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Reference;
use Spatie\LaravelData\Data;

class ReferenceDetailMediaData extends Data
{
    public function __construct(
        public string $front_cover_url,
        public string $back_cover_url,
    ) {}

    public static function fromModel(Reference $reference): self
    {
        return new self(
            front_cover_url: $reference->getFirstMediaUrl('front_cover', 'thumb') ?: $reference->getFirstMediaUrl('front_cover'),
            back_cover_url: $reference->getFirstMediaUrl('back_cover', 'thumb') ?: $reference->getFirstMediaUrl('back_cover'),
        );
    }
}
