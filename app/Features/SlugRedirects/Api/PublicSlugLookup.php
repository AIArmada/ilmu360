<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Api;

/**
 * Resolve a public slug to an immutable, scalar target identity.
 *
 * Transport adapters hydrate the registered model for Laravel route binding.
 */
interface PublicSlugLookup
{
    public function resolve(string $parameter, string $value): ResolvedPublicSlug;
}
