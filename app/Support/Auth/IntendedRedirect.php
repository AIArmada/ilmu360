<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;

class IntendedRedirect
{
    public static function captureFromRequest(Request $request): ?string
    {
        $redirect = self::sanitize($request->query('redirect'), $request);

        if ($redirect !== null) {
            $request->session()->put('url.intended', $redirect);
        }

        return $redirect;
    }

    public static function loginUrl(?string $redirect = null): string
    {
        return route('login', self::queryParameters($redirect));
    }

    public static function registerUrl(?string $redirect = null): string
    {
        return route('register', self::queryParameters($redirect));
    }

    public static function socialiteUrl(string $provider, ?string $redirect = null): string
    {
        return route('socialite.redirect', ['provider' => $provider, ...self::queryParameters($redirect)]);
    }

    /**
     * @return array{redirect?: string}
     */
    public static function queryParameters(?string $redirect = null): array
    {
        $redirect = self::sanitize($redirect);

        if ($redirect === null) {
            return [];
        }

        return ['redirect' => $redirect];
    }

    public static function logoutTarget(Request $request): string
    {
        $redirect = self::sanitize($request->input('redirect'), $request)
            ?? self::sanitize($request->headers->get('referer'), $request);

        return $redirect ?? route('home');
    }

    public static function sanitize(mixed $redirect, ?Request $request = null): ?string
    {
        if (! is_string($redirect)) {
            return null;
        }

        $redirect = trim($redirect);

        if ($redirect === '') {
            return null;
        }

        if (str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            return $redirect;
        }

        if (! filter_var($redirect, FILTER_VALIDATE_URL)) {
            return null;
        }

        $request ??= request();

        $parts = parse_url($redirect);

        if (! is_array($parts)) {
            return null;
        }

        if (($parts['host'] ?? null) !== $request->getHost()) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
