<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

/** Resolves the affiliate/share source attached to the current visit. */
interface SignupAttributionSourceResolver
{
    public function resolve(): ?SignupAttributionSource;
}
