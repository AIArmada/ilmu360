<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Visitor country resolution
    |--------------------------------------------------------------------------
    |
    | The public directories (majlis / institusi) scope their address filters to
    | a default country. When we can infer the visitor's country we use it so
    | that, for example, a visitor from Singapore is not silently shown only
    | Malaysian results.
    |
    | This application runs behind Cloudflare, so the cheapest and most accurate
    | signal is the `CF-IPCountry` request header — no GeoIP database or third
    | party API round-trip is required.
    |
    | SECURITY: a client can forge `CF-IPCountry` if it can reach this app
    | directly. Cloudflare overwrites the header on every proxied request, so it
    | is trustworthy *only* while the app is reachable solely through Cloudflare.
    | `enabled` is a kill switch for deployments that are not behind a proxy
    | which sets the header.
    |
    */

    'geoip' => [

        'enabled' => env('LOCATION_GEOIP_ENABLED', true),

        /*
         * Request header carrying an ISO-3166-1 alpha-2 country code.
         * Cloudflare also sends CF-IPCity / CF-IPContinent / CF-IPLatitude /
         * CF-IPLongitude when IP Geolocation is enabled for the zone.
         */
        'header' => env('LOCATION_GEOIP_HEADER', 'CF-IPCountry'),

        /*
         * Cloudflare uses XX when the country cannot be determined and T1 for
         * Tor exit nodes. Both must be treated as "unknown".
         */
        'indeterminate_codes' => ['XX', 'T1'],

    ],

    /*
     * Fallback used when no visitor signal is available (local development,
     * header disabled, unknown country). Resolved against the countries table.
     */
    'default_country_code' => env('LOCATION_DEFAULT_COUNTRY_CODE', env('CONTACTING_DEFAULT_COUNTRY_CODE', 'MY')),

];
