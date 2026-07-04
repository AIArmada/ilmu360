<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use AIArmada\CommerceSupport\Support\OwnerContext;
use Closure;
use Illuminate\Http\Request;

final class SetOwnerContextToGlobal
{
    public function handle(Request $request, Closure $next): mixed
    {
        return OwnerContext::withOwner(null, static fn (): mixed => $next($request));
    }
}
