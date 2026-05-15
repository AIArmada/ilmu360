<?php

namespace App\Support\Location;

use App\Models\Country;
use App\Support\Timezone\UserTimezoneResolver;
use DateTimeZone;
use Illuminate\Http\Request;

class PreferredCountryResolver
{
    public const MALAYSIA_ID = 132;

    public function resolveId(?Request $request = null): int
    {
        $request ??= request();

        $selectedCountryId = $this->normalizeSupportedCountryId(
            ($selectedCountryKey = app(PublicCountryPreference::class)->selectedKey($request)) !== null
                ? app(PublicCountryRegistry::class)->countryIdForKey($selectedCountryKey)
                : null
        );

        if ($selectedCountryId !== null) {
            return $selectedCountryId;
        }

        $timezoneCountryId = $this->normalizeSupportedCountryId($this->resolveFromTimezone($request));

        if ($timezoneCountryId !== null) {
            return $timezoneCountryId;
        }

        $ipCountryId = $this->normalizeSupportedCountryId($this->countryIdFromIso2($request->header('CF-IPCountry')));

        if ($ipCountryId !== null) {
            return $ipCountryId;
        }

        return self::MALAYSIA_ID;
    }

    public function countryIdFromIso2(?string $iso2): ?int
    {
        $normalizedIso2 = strtoupper(trim((string) $iso2));

        if ($normalizedIso2 === '' || strlen($normalizedIso2) !== 2) {
            return null;
        }

        $countryId = Country::query()
            ->where('iso2', $normalizedIso2)
            ->value('id');

        return is_int($countryId) ? $countryId : null;
    }

    public function countryIdFromTimezone(?string $timezone): ?int
    {
        $normalizedTimezone = trim((string) $timezone);

        if ($normalizedTimezone === '') {
            return null;
        }

        try {
            $location = timezone_location_get(new DateTimeZone($normalizedTimezone));
        } catch (\Exception) {
            return null;
        }

        if (! is_array($location)) {
            return null;
        }

        $countryCode = trim($location['country_code']);

        return $countryCode !== ''
            ? $this->countryIdFromIso2($countryCode)
            : null;
    }

    private function resolveFromTimezone(Request $request): ?int
    {
        $resolution = UserTimezoneResolver::resolveWithSource($request);

        if (in_array($resolution['source'], ['app_fallback', 'system_fallback'], true)) {
            return null;
        }

        return $this->countryIdFromTimezone($resolution['timezone']);
    }

    private function normalizeSupportedCountryId(?int $countryId): ?int
    {
        return app(PublicCountryRegistry::class)->normalizeCountryId($countryId);
    }
}
