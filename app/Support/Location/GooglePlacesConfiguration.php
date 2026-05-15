<?php

declare(strict_types=1);

namespace App\Support\Location;

final class GooglePlacesConfiguration
{
    public static function apiKey(): ?string
    {
        $apiKey = config('services.google.maps_api_key');

        if (! is_string($apiKey)) {
            return null;
        }

        $apiKey = trim($apiKey);

        return $apiKey === '' ? null : $apiKey;
    }

    public static function isEnabled(): bool
    {
        return (bool) config('services.google.places_enabled', false) && self::apiKey() !== null;
    }

    public static function serverApiKey(): ?string
    {
        $apiKey = config('services.google.places_server_api_key');

        if (! is_string($apiKey)) {
            return null;
        }

        $apiKey = trim($apiKey);

        return $apiKey === '' ? null : $apiKey;
    }

    public static function isLinkResolutionEnabled(): bool
    {
        return (bool) config('services.google.place_link_resolution_enabled', false);
    }

    public static function isServerLookupEnabled(): bool
    {
        return self::isLinkResolutionEnabled() && self::serverApiKey() !== null;
    }
}
