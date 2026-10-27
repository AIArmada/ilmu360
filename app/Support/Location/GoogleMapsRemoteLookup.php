<?php

namespace App\Support\Location;

use Illuminate\Support\Facades\Http;

final class GoogleMapsRemoteLookup
{
    public function __construct(private readonly GoogleMapsUrlParser $urls) {}

    public function resolveUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $currentUrl = $url;

        for ($redirectDepth = 0; $redirectDepth < 5; $redirectDepth++) {
            $currentUrl = $this->urls->unwrapConsent($currentUrl);

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

            $currentUrl = $this->urls->resolveRelative($currentUrl, $location);

            if (! ($this->urls->parse($currentUrl)['needs_redirect_resolution'] ?? false)) {
                return $currentUrl;
            }
        }

        return $this->urls->unwrapConsent($currentUrl);
    }

    /**
     * @return array{place_id: string, display_name: string|null, lat: float|null, lng: float|null}|null
     */
    public function searchByName(
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
                    'place_id' => GoogleMapsInputValues::placeId($place['id'] ?? null),
                    'display_name' => GoogleMapsInputValues::displayName($place['displayName'] ?? null),
                    'lat' => GoogleMapsInputValues::number($location['latitude'] ?? null),
                    'lng' => GoogleMapsInputValues::number($location['longitude'] ?? null),
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
     * @return array{display_name: string|null, lat: float|null, lng: float|null}|null
     */
    public function placeDetails(string $placeId): ?array
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
            'display_name' => GoogleMapsInputValues::displayName($response->json('displayName')),
            'lat' => GoogleMapsInputValues::number($response->json('location.latitude')),
            'lng' => GoogleMapsInputValues::number($response->json('location.longitude')),
        ];
    }

    private function normalizePlaceText(string $value): string
    {
        return (string) str($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/i', ' ')
            ->squish();
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
