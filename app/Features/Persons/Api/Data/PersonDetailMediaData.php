<?php

declare(strict_types=1);

namespace App\Features\Persons\Api\Data;

final readonly class PersonDetailMediaData
{
    public function __construct(
        public string $avatar_url,
        public string $main_url,
        public string $share_image_url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
