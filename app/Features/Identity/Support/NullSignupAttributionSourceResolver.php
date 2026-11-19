<?php

declare(strict_types=1);

namespace App\Features\Identity\Support;

use App\Features\Identity\Api\SignupAttributionSource;
use App\Shared\Auth\SignupAttributionSourceResolver;
use Illuminate\Http\Request;

final class NullSignupAttributionSourceResolver implements SignupAttributionSourceResolver
{
    public function resolve(?Request $request): ?SignupAttributionSource
    {
        return null;
    }
}
