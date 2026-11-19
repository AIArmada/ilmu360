<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Features\Identity\Api\SignupAttributionContext;
use Illuminate\Http\Request;

/** Resolves untrusted signup attribution context from the current request. */
interface SignupAttributionContextResolver
{
    public function resolve(?Request $request): SignupAttributionContext;
}
