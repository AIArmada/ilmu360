<?php

namespace App\Http\Middleware;

use App\Services\ShareTrackingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackShareAttribution
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
            name: (string) config('share-tracking.cookie.name', 'i360_share'),
            value: $cookieValue,
            minutes: (int) config('share-tracking.cookie.minutes', 60 * 24 * 30),
            path: (string) config('share-tracking.cookie.path', '/'),
            domain: config('share-tracking.cookie.domain'),
            secure: config('share-tracking.cookie.secure'),
            httpOnly: (bool) config('share-tracking.cookie.http_only', true),
            raw: false,
            sameSite: (string) config('share-tracking.cookie.same_site', 'lax'),
        ));

        return $response;
    }
}
