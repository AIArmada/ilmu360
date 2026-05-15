<?php

namespace App\Http\Middleware;

use App\Support\Timezone\UserTimezoneResolver;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetFilamentTimezone
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolution = UserTimezoneResolver::resolveWithSource($request);
        $timezone = $resolution['timezone'];

        FilamentTimezone::set($timezone);

        $user = Auth::user();
        $shouldPersistTimezone = in_array($resolution['source'], ['preferred', 'header', 'request_input', 'cookie'], true);

        if ($user !== null && $shouldPersistTimezone && data_get($user, 'timezone') !== $timezone) {
            $user->forceFill(['timezone' => $timezone])->save();
        }

        if ($request->hasSession()) {
            $request->session()->put('user_timezone', $timezone);
        }

        return $next($request);
    }
}
