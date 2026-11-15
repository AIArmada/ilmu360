<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Shared\Auth\AccountActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        abort_unless(
            $account instanceof AccountActor && $account->hasApplicationAdminAccess(),
            403,
        );

        return $next($request);
    }
}
