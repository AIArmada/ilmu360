<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Auth\SignupAttributionSource;
use Illuminate\Http\Request;

final class NullSignupAttributionSourceResolver implements SignupAttributionSourceResolver
{
    public function resolve(?Request $request): ?SignupAttributionSource
    {
        return null;
    }
}
