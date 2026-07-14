<?php

namespace App\Actions\Location;

use App\Support\Location\GooglePlacesConfiguration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class NormalizeGoogleMapsInputAction
{
    use AsAction;

    private const string PartialStatus = 'partial';

    private const string ResolvedStatus = 'resolved';

    private const string UnresolvedStatus = 'unresolved';

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     google_maps_url: string|null,
     *     google_place_id: string|null,
     *     google_display_name: string|null,
     *     lat: float|null,
     *     lng: float|null,
     *     google_resolution_source: string|null,
     *     google_resolution_status: 'resolved'|'partial'|'unresolved',
     *     google_resolution_fingerprint: string|null,
     *     google_resolution_message: string|null
     * }
     */
    public function handle(array $input): array
    {
        $rawUrl = $this->normalizeString($input['google_maps_url'] ?? null);
        $displayName = $this->displayNameValue($input['google_display_name'] ?? $input['display_name'] ?? null);
        $placeId = $this->normalizePlaceId($input['google_place_id'] ?? null);
        $lat = $this->numericValue($input['lat'] ?? null);
        $lng = $this->numericValue($input['lng'] ?? null);
        $remoteLookupEnabled = $this->remoteLookupEnabled($input['google_maps_remote_lookup_enabled'] ?? null);
        $source = $this->normalizeSource($input['google_resolution_source'] ?? $input['resolution_source'] ?? null);
        $previousStatus = $this->normalizeStatus($input['google_resolution_status'] ?? null);
        $previousFingerprint = $this->normalizeString($input['google_resolution_fingerprint'] ?? null);

        $currentUrl = $this->unwrapGoogleConsentUrl($rawUrl);
        $currentFingerprint = $this->fingerprint($currentUrl);
        $inputChanged = $previousFingerprint !== null
            && $currentFingerprint !== null
            && $currentFingerprint !== $previousFingerprint;

        if ($rawUrl !== null && $inputChanged) {
            $placeId = null;
            $lat = null;
            $lng = null;
            $displayName = null;
            $source = 'manual';
        }

        $parsed = $this->parseGoogleMapsUrl($currentUrl);

        $currentUrl = $parsed['url'] ?? $currentUrl;
        $currentFingerprint = $this->fingerprint($currentUrl);
        $placeId ??= $parsed['place_id'];
        $displayName ??= $parsed['display_name'];

        if (($lat === null) || ($lng === null)) {
            $lat ??= $parsed['lat'];
            $lng ??= $parsed['lng'];
        }

        $shouldSkipRemoteRetry = $currentFingerprint !== null
            && $currentFingerprint === $previousFingerprint
            && in_array($previousStatus, [self::PartialStatus, self::UnresolvedStatus], true);

        if (
            ! $shouldSkipRemoteRetry
            && ($parsed['needs_redirect_resolution'] ?? false)
            && ! (($placeId !== null) && ($lat !== null) && ($lng !== null))
            && GooglePlacesConfiguration::isLinkResolutionEnabled()
        ) {
            $resolvedUrl = $this->resolveGoogleMapsUrl($currentUrl);

            if ($resolvedUrl !== $currentUrl) {
                $currentUrl = $resolvedUrl;
                $parsed = $this->parseGoogleMapsUrl($currentUrl);
                $currentFingerprint = $this->fingerprint($currentUrl);
                $placeId ??= $parsed['place_id'];
                $displayName ??= $parsed['display_name'];
                $lat ??= $parsed['lat'];
                $lng ??= $parsed['lng'];
            }
        }

        if (
            ! $shouldSkipRemoteRetry
            && $placeId === null
            && $displayName !== null
            && $remoteLookupEnabled
            && GooglePlacesConfiguration::isServerLookupEnabled()
        ) {
            $resolvedPlace = $this->searchPlaceByName(
                $displayName,
                $lat,
                $lng,
                $this->countryCode($input),
            );

            if ($resolvedPlace !== null) {
                $placeId = $resolvedPlace['place_id'];
                $lat ??= $resolvedPlace['lat'];
                $lng ??= $resolvedPlace['lng'];
            }
        }

        if (
            ! $shouldSkipRemoteRetry
            && $placeId !== null
            && $rawUrl === null
            && (($lat === null) || ($lng === null) || ($displayName === null))
            && $remoteLookupEnabled
            && GooglePlacesConfiguration::isServerLookupEnabled()
        ) {
            $placeDetails = $this->fetchPlaceDetails($placeId);

            if ($placeDetails !== null) {
                $displayName ??= $placeDetails['display_name'];
                $lat ??= $placeDetails['lat'];
                $lng ??= $placeDetails['lng'];
            }
        }

        $canonicalUrl = $this->buildCanonicalGoogleMapsUrl($placeId, $lat, $lng, $displayName);
        $normalizedUrl = $canonicalUrl ?? $currentUrl;
        $normalizedFingerprint = $this->fingerprint($normalizedUrl);
        $normalizedSource = $source ?? ($normalizedUrl !== null || $placeId !== null ? 'manual' : null);
        $status = $this->resolveStatus($normalizedUrl, $placeId, $lat, $lng);

        return [
            'google_maps_url' => $normalizedUrl,
            'google_place_id' => $placeId,
            'google_display_name' => $displayName,
            'lat' => $lat,
            'lng' => $lng,
            'google_resolution_source' => $normalizedSource,
            'google_resolution_status' => $status,
            'google_resolution_fingerprint' => $normalizedFingerprint,
            'google_resolution_message' => $this->resolutionMessage($status, $rawUrl, $placeId),
        ];
    }

    private function buildCanonicalGoogleMapsUrl(?string $placeId, ?float $lat, ?float $lng, ?string $displayName): ?string
    {
        if (($lat !== null) && ($lng !== null)) {
            $parameters = [
                'api' => '1',
                'query' => $this->formatCoordinate($lat).','.$this->formatCoordinate($lng),
            ];

            if ($placeId !== null) {
                $parameters['query_place_id'] = $placeId;
            }

            return 'https://www.google.com/maps/search/?'.http_build_query($parameters);
        }

        if ($placeId === null) {
            return null;
        }

        return 'https://www.google.com/maps/search/?'.http_build_query([
            'api' => '1',
            'query' => $displayName ?? $placeId,
            'query_place_id' => $placeId,
        ]);
    }

    private function resolveGoogleMapsUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $currentUrl = $url;

        for ($redirectDepth = 0; $redirectDepth < 5; $redirectDepth++) {
            $currentUrl = $this->unwrapGoogleConsentUrl($currentUrl);

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'ilmu360/1.0 (+'.config('app.url', 'https://ilmu360.test').')',
                ])
                    ->withOptions(['allow_redirects' => false])
                    ->timeout(10)
                    ->get($currentUrl);
            } catch (\Throwable) {
                return $currentUrl;
            }

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $currentUrl;
            }

            $location = $response->header('Location');

            if (! is_string($location) || trim($location) === '') {
                return $currentUrl;
            }

            $currentUrl = $this->resolveRelativeUrl($currentUrl, $location);

            if (! ($this->parseGoogleMapsUrl($currentUrl)['needs_redirect_resolution'] ?? false)) {
                return $currentUrl;
            }
        }

        return $this->unwrapGoogleConsentUrl($currentUrl);
    }

    /**
     * @return array{
     *     url: string|null,
     *     place_id: string|null,
     *     display_name: string|null,
     *     lat: float|null,
     *     lng: float|null,
     *     needs_redirect_resolution: bool
     * }
     */
    private function parseGoogleMapsUrl(?string $url): array
    {
        $trimmedUrl = $this->normalizeString($url);

        if ($trimmedUrl === null) {
            return [
                'url' => null,
                'place_id' => null,
                'display_name' => null,
                'lat' => null,
                'lng' => null,
                'needs_redirect_resolution' => false,
            ];
        }

        $host = parse_url($trimmedUrl, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : null;
        $query = parse_url($trimmedUrl, PHP_URL_QUERY);
        $path = parse_url($trimmedUrl, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        $queryParams = [];

        if (is_string($query) && $query !== '') {
            parse_str($query, $queryParams);
        }

        $placeId = $this->normalizePlaceId($queryParams['query_place_id'] ?? null);
        $displayName = $this->extractDisplayNameFromPath($path);
        $coordinates = $this->extractCoordinatesFromGoogleMapsUrl($trimmedUrl);

        foreach (['q', 'query', 'destination'] as $queryKey) {
            $queryValue = $this->normalizeString($queryParams[$queryKey] ?? null);

            if ($queryValue === null) {
                continue;
            }

            if ($placeId === null) {
                $placeId = $this->extractPlaceIdFromQueryValue($queryValue);
            }

            $parsedQuery = $this->extractQueryDisplayNameAndCoordinates($queryValue);

            $displayName ??= $parsedQuery['display_name'];
            $coordinates ??= $parsedQuery['coordinates'];
        }

        return [
            'url' => $trimmedUrl,
            'place_id' => $placeId,
            'display_name' => $displayName,
            'lat' => $coordinates['lat'] ?? null,
            'lng' => $coordinates['lng'] ?? null,
            'needs_redirect_resolution' => $this->needsRedirectResolution($host, $queryParams),
        ];
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private function needsRedirectResolution(?string $host, array $queryParams): bool
    {
        if ($host === null) {
            return false;
        }

        if (in_array($host, ['maps.app.goo.gl', 'goo.gl', 'g.co'], true)) {
            return true;
        }

        return $this->isGoogleMapsHost($host) && filled($queryParams['cid'] ?? null);
    }

    /**
     * @return array{place_id: string, display_name: string|null, lat: float|null, lng: float|null}|null
     */
    private function searchPlaceByName(
        string $displayName,
        ?float $lat,
        ?float $lng,
        ?string $countryCode,
    ): ?array {
        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => GooglePlacesConfiguration::serverApiKey(),
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.location',
            ])
                ->timeout(10)
                ->post('https://places.googleapis.com/v1/places:searchText', array_filter([
                    'textQuery' => $displayName,
                    'regionCode' => $countryCode,
                    'locationBias' => (($lat !== null) && ($lng !== null))
                        ? [
                            'circle' => [
                                'center' => [
                                    'latitude' => $lat,
                                    'longitude' => $lng,
                                ],
                                'radius' => 1500.0,
                            ],
                        ]
                        : null,
                ], static fn (mixed $value): bool => $value !== null));
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $expectedName = $this->normalizePlaceText($displayName);
        /** @var array<int, mixed> $places */
        $places = $response->json('places', []);

        $matches = collect($places)
            ->filter(fn (mixed $place): bool => is_array($place))
            ->map(function (array $place): array {
                $location = $place['location'] ?? [];

                return [
                    'place_id' => $this->normalizePlaceId($place['id'] ?? null),
                    'display_name' => $this->displayNameValue($place['displayName'] ?? null),
                    'lat' => $this->numericValue($location['latitude'] ?? null),
                    'lng' => $this->numericValue($location['longitude'] ?? null),
                ];
            })
            ->filter(fn (array $place): bool => $place['place_id'] !== null && $place['display_name'] !== null)
            ->filter(function (array $place) use ($expectedName, $lat, $lng): bool {
                if ($this->normalizePlaceText($place['display_name']) !== $expectedName) {
                    return false;
                }

                if (($lat === null) || ($lng === null) || ($place['lat'] === null) || ($place['lng'] === null)) {
                    return true;
                }

                return $this->distanceInMeters($lat, $lng, $place['lat'], $place['lng']) <= 1500;
            })
            ->values();

        if ($matches->count() !== 1) {
            return null;
        }

        /** @var array{place_id: string, display_name: string|null, lat: float|null, lng: float|null} $match */
        $match = $matches->first();

        return $match;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function countryCode(array $input): ?string
    {
        $countryCode = $input['country_code'] ?? null;

        if (! is_string($countryCode) || preg_match('/^[A-Za-z]{2}$/', trim($countryCode)) !== 1) {
            return null;
        }

        return mb_strtoupper(trim($countryCode));
    }

    /**
     * @return array{display_name: string|null, lat: float|null, lng: float|null}|null
     */
    private function fetchPlaceDetails(string $placeId): ?array
    {
        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => GooglePlacesConfiguration::serverApiKey(),
                'X-Goog-FieldMask' => 'id,displayName,location',
            ])
                ->timeout(10)
                ->get('https://places.googleapis.com/v1/places/'.rawurlencode($placeId));
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return [
            'display_name' => $this->displayNameValue($response->json('displayName')),
            'lat' => $this->numericValue($response->json('location.latitude')),
            'lng' => $this->numericValue($response->json('location.longitude')),
        ];
    }

    private function resolutionMessage(string $status, ?string $rawUrl, ?string $placeId): ?string
    {
        if (($rawUrl === null) && ($placeId === null)) {
            return null;
        }

        return match ($status) {
            self::ResolvedStatus => null,
            self::PartialStatus => __('Google place ID could not be confirmed from this link. The normalized Google Maps URL will still be saved.'),
            default => __('This Google Maps link could not be fully normalized. It will be saved as-is unless more location details are provided.'),
        };
    }

    private function resolveStatus(?string $url, ?string $placeId, ?float $lat, ?float $lng): string
    {
        if ($placeId !== null) {
            return self::ResolvedStatus;
        }

        if (($url !== null) || (($lat !== null) && ($lng !== null))) {
            return self::PartialStatus;
        }

        return self::UnresolvedStatus;
    }

    private function normalizeSource(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        return in_array($normalized, ['manual', 'picker'], true) ? $normalized : null;
    }

    private function remoteLookupEnabled(mixed $value): bool
    {
        return filter_var($value ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private function normalizeStatus(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        return in_array($normalized, [self::ResolvedStatus, self::PartialStatus, self::UnresolvedStatus], true)
            ? $normalized
            : null;
    }

    private function fingerprint(?string $url): ?string
    {
        return $url === null ? null : sha1($url);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizePlaceId(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        if ($normalized === null) {
            return null;
        }

        return Str::after($normalized, 'places/');
    }

    private function displayNameValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $text = $value['text'] ?? null;

            return $this->normalizeString(is_string($text) ? $text : null);
        }

        return $this->normalizeString($value);
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return is_numeric($trimmed) ? (float) $trimmed : null;
    }

    private function unwrapGoogleConsentUrl(?string $url): ?string
    {
        $currentUrl = $url;

        for ($redirectDepth = 0; $redirectDepth < 3; $redirectDepth++) {
            $host = parse_url((string) $currentUrl, PHP_URL_HOST);
            $host = is_string($host) ? strtolower($host) : '';

            if ($host !== 'consent.google.com') {
                return $currentUrl;
            }

            $query = parse_url((string) $currentUrl, PHP_URL_QUERY);

            if (! is_string($query) || $query === '') {
                return $currentUrl;
            }

            parse_str($query, $parameters);

            $continueUrl = $parameters['continue'] ?? null;

            if (! is_string($continueUrl) || trim($continueUrl) === '') {
                return $currentUrl;
            }

            $currentUrl = str_starts_with($continueUrl, '/')
                ? 'https://www.google.com'.$continueUrl
                : $continueUrl;
        }

        return $currentUrl;
    }

    private function resolveRelativeUrl(string $baseUrl, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location) === 1) {
            return $location;
        }

        $baseParts = parse_url($baseUrl);
        $scheme = is_string($baseParts['scheme'] ?? null) ? $baseParts['scheme'] : 'https';
        $host = is_string($baseParts['host'] ?? null) ? $baseParts['host'] : '';
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$port.$location;
        }

        $basePath = is_string($baseParts['path'] ?? null) ? $baseParts['path'] : '/';
        $directory = rtrim((string) preg_replace('~/[^/]*$~', '/', $basePath), '/');

        return $scheme.'://'.$host.$port.($directory).'/'.$location;
    }

    private function isGoogleMapsHost(string $host): bool
    {
        return preg_match('/(^|\.)google\.[a-z.]+$/', $host) === 1;
    }

    private function extractDisplayNameFromPath(string $path): ?string
    {
        if (preg_match('~/maps/place/([^/@]+)~', $path, $matches) !== 1) {
            return null;
        }

        $decoded = str_replace('+', ' ', urldecode($matches[1]));

        return $this->normalizeString($decoded);
    }

    private function extractPlaceIdFromQueryValue(string $queryValue): ?string
    {
        if (preg_match('/place_id:([^&]+)/i', $queryValue, $matches) !== 1) {
            return null;
        }

        return $this->normalizePlaceId($matches[1]);
    }

    /**
     * @return array{display_name: string|null, coordinates: array{lat: float, lng: float}|null}
     */
    private function extractQueryDisplayNameAndCoordinates(string $queryValue): array
    {
        if (preg_match('/(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/', $queryValue, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $displayName = substr($queryValue, 0, $matches[0][1]);
            $displayName = trim(preg_replace('/[,\s]+$/', '', $displayName) ?? '');

            return [
                'display_name' => $this->normalizeString($displayName),
                'coordinates' => [
                    'lat' => (float) $matches[1][0],
                    'lng' => (float) $matches[2][0],
                ],
            ];
        }

        return [
            'display_name' => $this->normalizeString(str_replace('+', ' ', $queryValue)),
            'coordinates' => null,
        ];
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function extractCoordinatesFromGoogleMapsUrl(?string $url): ?array
    {
        if (! is_string($url) || ! filled($url)) {
            return null;
        }

        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $matches) === 1) {
            return [
                'lat' => (float) $matches[1],
                'lng' => (float) $matches[2],
            ];
        }

        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $matches) === 1) {
            return [
                'lat' => (float) $matches[1],
                'lng' => (float) $matches[2],
            ];
        }

        if (preg_match('/(?:query|destination)=[^&#]*?(-?\d+\.\d+)(?:,|%2C)(-?\d+\.\d+)/i', $url, $matches) === 1) {
            return [
                'lat' => (float) $matches[1],
                'lng' => (float) $matches[2],
            ];
        }

        return null;
    }

    private function normalizePlaceText(string $value): string
    {
        return (string) str($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/i', ' ')
            ->squish();
    }

    private function formatCoordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');
    }

    private function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $angle = 2 * asin(sqrt(
            sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2
        ));

        return $earthRadius * $angle;
    }
}
