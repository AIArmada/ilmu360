<?php

declare(strict_types=1);

namespace Tests\Support;

use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FreshTimezoneCapture
{
    public static ?string $timezone = null;

    /**
     * @param  \Closure(Request): Response  $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        self::$timezone = FilamentTimezone::get();

        return $next($request);
    }
}
