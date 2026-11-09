<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Api;

final readonly class InspirationSnapshot
{
    public function __construct(
        public string $id,
        public string $category,
        public string $locale,
        public string $title,
        public ?string $source,
        public string $status,
    ) {}
}
