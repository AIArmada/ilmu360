<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Api;

final readonly class SlugRedirectMatch
{
    public function __construct(
        public string $id,
        public string $redirectableType,
        public string $redirectableId,
    ) {}
}
