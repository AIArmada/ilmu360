<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use Closure;
use Illuminate\Http\Request;

final class RecordSuccessfulLogin
{
    public function __invoke(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User) {
            app(ProductSignalsService::class)->recordLogin($user, $request, 'password');
        }

        return $response;
    }
}
