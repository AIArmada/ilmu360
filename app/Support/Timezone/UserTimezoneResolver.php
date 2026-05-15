<?php

namespace App\Support\Timezone;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserTimezoneResolver
{
    /**
     * @var array<int, string>|null
     */
    protected static ?array $validTimezones = null;

    public static function resolve(?Request $request = null, ?string $preferredTimezone = null): string
    {
        return self::resolveWithSource($request, $preferredTimezone)['timezone'];
    }

    /**
     * @return array{timezone: string, source: 'preferred'|'authenticated_user'|'header'|'request_input'|'cookie'|'session'|'app_fallback'|'system_fallback'}
     */
    public static function resolveWithSource(?Request $request = null, ?string $preferredTimezone = null): array
    {
        $request ??= request();
        $sessionTimezone = $request->hasSession() ? $request->session()->get('user_timezone') : null;
        $authenticatedUserTimezone = Auth::check() ? data_get(Auth::user(), 'timezone') : null;
        $requestInputTimezone = $request->request->get('user_timezone') ?? $request->query('user_timezone');

        $candidates = [
            'preferred' => $preferredTimezone,
            'authenticated_user' => $authenticatedUserTimezone,
            'header' => $request->header('X-Timezone'),
            'request_input' => $requestInputTimezone,
            'cookie' => $request->cookie('user_timezone'),
            'session' => $sessionTimezone,
        ];

        foreach ($candidates as $source => $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            if (self::isValid($candidate)) {
                return [
                    'timezone' => $candidate,
                    'source' => $source,
                ];
            }
        }

        $fallback = (string) config('app.timezone', config('app.default_user_timezone', 'UTC'));

        if (self::isValid($fallback)) {
            return [
                'timezone' => $fallback,
                'source' => 'app_fallback',
            ];
        }

        $defaultUserTimezone = (string) config('app.default_user_timezone', 'UTC');

        if (self::isValid($defaultUserTimezone)) {
            return [
                'timezone' => $defaultUserTimezone,
                'source' => 'app_fallback',
            ];
        }

        return [
            'timezone' => 'UTC',
            'source' => 'system_fallback',
        ];
    }

    protected static function isValid(string $timezone): bool
    {
        self::$validTimezones ??= \DateTimeZone::listIdentifiers();

        return in_array($timezone, self::$validTimezones, true);
    }
}
