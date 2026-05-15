<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = array_keys(config('app.supported_locales', []));
        $locale = $request->session()->get('locale', config('app.locale'));

        $requestedLocale = $request->query('lang');
        if (is_string($requestedLocale) && in_array($requestedLocale, $supportedLocales, true)) {
            $locale = $requestedLocale;
            $request->session()->put('locale', $locale);
        }

        if (! in_array($locale, $supportedLocales, true)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);
        App::setFallbackLocale($locale === 'ar' ? 'en' : (string) config('app.fallback_locale'));
        Carbon::setLocale($locale);

        return $next($request);
    }
}
