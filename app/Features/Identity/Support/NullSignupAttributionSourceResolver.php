<?php

declare(strict_types=1);

namespace App\Features\Identity\Support;

use App\Features\Identity\Api\SignupAttributionSource;
use App\Features\Identity\Api\SignupAttributionSourceResolver;

final class NullSignupAttributionSourceResolver implements SignupAttributionSourceResolver
{
    public function resolve(): ?SignupAttributionSource
    {
        return null;
    }
}
