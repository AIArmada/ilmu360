<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Features\Identity\Api\SignupAttributionSource;
use Illuminate\Http\Request;

/** Resolves the affiliate/share source attached to the current visit. */
interface SignupAttributionSourceResolver
{
    public function resolve(?Request $request): ?SignupAttributionSource;
}
