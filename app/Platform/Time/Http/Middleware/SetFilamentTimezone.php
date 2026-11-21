<?php

namespace App\Platform\Time\Http\Middleware;

use App\Shared\Contracts\AccountTimezoneUpdater;
use App\Platform\Time\UserTimezoneResolver;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetFilamentTimezone
{
    public function __construct(private readonly AccountTimezoneUpdater $accountSettings) {}

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
            $this->accountSettings->updateTimezone((string) $user->getAuthIdentifier(), $timezone);
        }

        if ($request->hasSession()) {
            $request->session()->put('user_timezone', $timezone);
        }

        return $next($request);
    }
}
