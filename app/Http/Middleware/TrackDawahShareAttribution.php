<?php

namespace App\Http\Middleware;

use App\Services\ShareTrackingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackDawahShareAttribution
{
    public function __construct(
        private readonly ShareTrackingService $shareTrackingService
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cookieValue = $this->shareTrackingService->captureRequest($request);

        /** @var Response $response */
        $response = $next($request);

        if (! is_string($cookieValue) || $cookieValue === '') {
            return $response;
        }

        $response->headers->setCookie(cookie(
            name: (string) config('dawah-share.cookie.name', 'mi_dawah_share'),
            value: $cookieValue,
            minutes: (int) config('dawah-share.cookie.minutes', 60 * 24 * 30),
            path: (string) config('dawah-share.cookie.path', '/'),
            domain: config('dawah-share.cookie.domain'),
            secure: config('dawah-share.cookie.secure'),
            httpOnly: (bool) config('dawah-share.cookie.http_only', true),
            raw: false,
            sameSite: (string) config('dawah-share.cookie.same_site', 'lax'),
        ));

        return $response;
    }
}
