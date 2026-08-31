<?php

declare(strict_types=1);

namespace App\Support\Location;

use AIArmada\Addressing\Support\AddressCountryResolver;
use App\Support\Cache\SelectionCatalogCache;
use Illuminate\Http\Request;

/**
 * Resolves the country a public directory page should scope itself to.
 *
 * Candidate order:
 *   1. The configured edge geo header (Cloudflare `CF-IPCountry` by default).
 *   2. The configured application country (`MY`), used as the fallback.
 *
 * Resolutions are memoised per request because a single render can ask for the
 * default several times (component mount, the Filament schema, the view's
 * active-filter accounting).
 */
final class VisitorCountryResolver
{
    private static ?string $cacheScope = null;

    /**
     * @return array{
     *     country_id: string|null,
     *     country_code: string|null,
     *     source: 'edge_header'|'app_fallback'|'unresolved'
     * }
     */
    private static array $memoized = [
        'country_id' => null,
        'country_code' => null,
        'source' => 'unresolved',
    ];

    public function __construct(
        private readonly SelectionCatalogCache $catalogCache,
        private readonly AddressCountryResolver $countryResolver,
    ) {}

    /**
     * Reset the per-request memo. Modelled on SharedFormSchema::ensureCacheScope()
     * so Octane workers do not leak one visitor's country into the next request.
     */
    private static function ensureCacheScope(): void
    {
        $scope = spl_object_hash(app()).':'.(app()->bound('request') ? spl_object_hash(request()) : 'console');

        if (self::$cacheScope === $scope) {
            return;
        }

        self::$cacheScope = $scope;
        self::$memoized = [
            'country_id' => null,
            'country_code' => null,
            'source' => 'unresolved',
        ];
    }

    /**
     * The country UUID to scope address filters to, or null when no country
     * could be resolved.
     */
    public function resolve(?Request $request = null): ?string
    {
        return $this->resolveWithSource($request)['country_id'];
    }

    /**
     * The ISO-3166-1 alpha-2 code, or null when unresolved.
     */
    public function resolveCode(?Request $request = null): ?string
    {
        return $this->resolveWithSource($request)['country_code'];
    }

    /**
     * @return array{
     *     country_id: string|null,
     *     country_code: string|null,
     *     source: 'edge_header'|'app_fallback'|'unresolved'
     * }
     */
    public function resolveWithSource(?Request $request = null): array
    {
        self::ensureCacheScope();

        if (self::$memoized['source'] !== 'unresolved') {
            return self::$memoized;
        }

        $headerCode = $this->headerCountryCode($request);

        if ($headerCode !== null) {
            $countryId = $this->countryIdForCode($headerCode);

            if ($countryId !== null) {
                return self::$memoized = [
                    'country_id' => $countryId,
                    'country_code' => $headerCode,
                    'source' => 'edge_header',
                ];
            }
        }

        $fallbackCode = mb_strtoupper(mb_trim((string) config('location.default_country_code', 'MY')));

        if ($fallbackCode === '') {
            return self::$memoized;
        }

        $countryId = $this->countryIdForCode($fallbackCode);

        if ($countryId === null) {
            return self::$memoized;
        }

        return self::$memoized = [
            'country_id' => $countryId,
            'country_code' => $fallbackCode,
            'source' => 'app_fallback',
        ];
    }

    /**
     * Drop the memoized resolution so the next call re-resolves.
     */
    public function forget(): void
    {
        self::$cacheScope = null;
        self::$memoized = [
            'country_id' => null,
            'country_code' => null,
            'source' => 'unresolved',
        ];
    }

    /**
     * Read and validate the edge-provided country code.
     */
    private function headerCountryCode(?Request $request): ?string
    {
        if (! config('location.geoip.enabled', true)) {
            return null;
        }

        $header = config('location.geoip.header', 'CF-IPCountry');

        if (! is_string($header) || $header === '') {
            return null;
        }

        $request ??= request();

        if (! $request instanceof Request) {
            return null;
        }

        $code = mb_strtoupper(mb_trim((string) $request->header($header, '')));

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }

        /** @var list<string> $indeterminate */
        $indeterminate = config('location.geoip.indeterminate_codes', ['XX', 'T1']);

        if (in_array($code, $indeterminate, true)) {
            return null;
        }

        return $code;
    }

    /**
     * Resolve an ISO code to a country UUID, requiring the country to actually
     * exist so a forged header cannot smuggle an arbitrary value through.
     */
    private function countryIdForCode(string $code): ?string
    {
        $countryId = $this->catalogCache->rememberAddressValue(
            "visitor-country:{$code}",
            fn (): ?string => $this->countryResolver->resolveId($code),
        );

        return is_string($countryId) && $countryId !== '' ? $countryId : null;
    }
}
