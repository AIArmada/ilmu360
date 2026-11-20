<?php

declare(strict_types=1);

namespace App\Features\Persons\Api\Data;

final readonly class PersonGalleryItemData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $thumb_url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
