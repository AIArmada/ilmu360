<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Api;

final readonly class ResolvedPublicSlug
{
    public function __construct(
        public string $modelType,
        public string $modelId,
        public ?string $redirectTo,
        public ?string $slugRedirectId,
    ) {}
}
