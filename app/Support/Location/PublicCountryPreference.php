<?php

namespace App\Support\Location;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class PublicCountryPreference
{
    public const COOKIE_NAME = 'public_country';

    public const SESSION_KEY = 'public_country';

    public function selectedKey(?Request $request = null): ?string
    {
        $request ??= request();

        $sessionValue = $request->hasSession()
            ? $request->session()->get(self::SESSION_KEY)
            : null;

        if (is_string($sessionValue) && $sessionValue !== '') {
            $selectedSessionKey = $this->normalizeSelectedKey($sessionValue);

            if ($selectedSessionKey !== null) {
                return $selectedSessionKey;
            }
        }

        $cookieValue = $request->cookie(self::COOKIE_NAME);

        if (is_string($cookieValue) && $cookieValue !== '') {
            $selectedCookieKey = $this->normalizeSelectedKey($cookieValue);

            if ($selectedCookieKey !== null) {
                return $selectedCookieKey;
            }
        }

        return null;
    }

    public function currentKey(?Request $request = null): string
    {
        return $this->selectedKey($request) ?? $this->registry()->defaultKey();
    }

    /**
     * @return array{key: string, label: string, flag: string, iso2: string, default_timezone: string, enabled: bool, coming_soon: bool}
     */
    public function current(?Request $request = null): array
    {
        return $this->registry()->country($this->currentKey($request));
    }

    public function set(string $countryKey, ?Request $request = null): string
    {
        $request ??= request();

        $normalizedKey = $this->registry()->normalizeCountryKey($countryKey);

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $normalizedKey);
        }

        Cookie::queue(Cookie::forever(
            name: self::COOKIE_NAME,
            value: $normalizedKey,
            path: '/',
            domain: config('session.domain'),
            secure: config('session.secure'),
            httpOnly: false,
            sameSite: (string) config('session.same_site', 'lax'),
        ));

        return $normalizedKey;
    }

    protected function registry(): PublicCountryRegistry
    {
        return app(PublicCountryRegistry::class);
    }

    protected function normalizeSelectedKey(?string $countryKey): ?string
    {
        $normalizedKey = strtolower(trim((string) $countryKey));

        if ($normalizedKey === '' || ! $this->registry()->has($normalizedKey)) {
            return null;
        }

        return $this->registry()->isEnabled($normalizedKey)
            ? $normalizedKey
            : null;
    }
}
