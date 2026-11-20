<?php

declare(strict_types=1);

namespace App\Features\References\Api\Data;

final readonly class ReferenceDetailMediaData
{
    public function __construct(
        public string $front_cover_url,
        public string $back_cover_url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
