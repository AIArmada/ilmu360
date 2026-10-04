<?php

declare(strict_types=1);

use App\Contracts\NullPrayerTimesProvider;
use App\Services\Prayer\AladhanPrayerProvider;
use App\Services\Prayer\JakimMirrorProvider;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\NullZoneResolver;
use App\Services\Prayer\UmmahPrayerProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Provider-backed resolution
    |--------------------------------------------------------------------------
    |
    | Gates provider clocks on the submit path. When disabled, every prayer
    | timing resolves from the hardcoded estimates exactly as before.
    |
    */

    'enabled' => (bool) env('PRAYER_PROVIDERS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Queues monitored by app:prayer:stats
    |--------------------------------------------------------------------------
    |
    | The stats command aggregates depth across these queues and the
    | --alert-queue-depth threshold applies to that total. Null derives the
    | set from the Horizon supervisors so the total covers the whole worker
    | fleet instead of silently measuring the default queue alone.
    |
    */

    'monitored_queues' => null,

    /*
    |--------------------------------------------------------------------------
    | Provider map and per-country order
    |--------------------------------------------------------------------------
    |
    | Registry keys resolve to provider classes; order lists run head to tail
    | with the first available provider winning. Unmapped countries use the
    | global default chain. Class strings are safe here: referencing ::class
    | never autoloads, and the registry skips unknown classes.
    |
    */

    'provider_map' => [
        'jakim' => JakimMirrorProvider::class,
        'ummah' => UmmahPrayerProvider::class,
        'aladhan' => AladhanPrayerProvider::class,
        'null' => NullPrayerTimesProvider::class,
    ],

    'providers' => [
        'MY' => ['jakim', 'ummah', 'aladhan'],
    ],

    'providers_default' => ['ummah', 'aladhan'],

    /*
    |--------------------------------------------------------------------------
    | Zone resolvers per country
    |--------------------------------------------------------------------------
    |
    | Maps countries with zone-based prayer authorities to their resolver.
    | Unmapped countries are coordinate-only and use the null adapter. A new
    | zone-based country slots in with one row here plus its resolver class
    | (and zone snapshot); no caller branches on country.
    |
    */

    'zone_resolvers' => [
        'MY' => JakimZoneResolver::class,
    ],

    'zone_resolver_default' => NullZoneResolver::class,

    /*
    |--------------------------------------------------------------------------
    | Display timezones per country
    |--------------------------------------------------------------------------
    |
    | Default timezone where the caller supplies none (preview endpoint,
    | prefetch month boundaries). Unmapped countries use UTC.
    |
    */

    'country_timezones' => [
        'MY' => 'Asia/Kuala_Lumpur',
    ],

    'country_timezone_default' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Calculation methods per country (coordinate providers)
    |--------------------------------------------------------------------------
    |
    | Ummah method keys from the live /api/prayer-times/methods payload;
    | Aladhan consumes the matching numeric method. Defaults apply when a
    | country has no row.
    |
    */

    'methods' => [
        'default' => ['ummah' => 'MuslimWorldLeague', 'madhab' => 'Shafi', 'aladhan' => 3],
        'MY' => ['ummah' => 'malaysia', 'madhab' => 'Shafi', 'aladhan' => 17],
        'SG' => ['ummah' => 'Singapore', 'madhab' => 'Shafi', 'aladhan' => 11],
    ],

    /*
    |--------------------------------------------------------------------------
    | Announced Ramadan windows (Tarawih gate, V2.4)
    |--------------------------------------------------------------------------
    |
    | Official declaration dates are PRIMARY and exact: a day inside a window
    | is Ramadan, with no tolerance. Days no window governs (unannounced
    | years, December straddlers) fall back to the tabular estimate with
    | ±1-day tolerance. Windows stay within their key year; extend the list
    | as new declarations are announced.
    |
    */

    'ramadan' => [
        // Official declarations keyed by ISO2 country (or authority) code.
        // ONLY actual declarations belong here: announced windows are
        // exact and suppress the ±1-day estimate tolerance, so forecast
        // years must stay out until their declaration process concludes
        // (a declared boundary may legitimately differ from any forecast).
        // Countries — and future seasons — without a row use the tabular
        // estimate. Windows store Tarawih evenings (one day before the
        // corresponding fasting dates). MY rows verified against JAKIM
        // e-Solat tarikhtakwim (hisab-based; re-confirm after the formal
        // rukyah announcement): 2026 fasting Feb 19–Mar 20, 2027 fasting
        // Feb 8–Mar 9. No 2028 row: the endpoint returns incoherent
        // 1449 dates (day-zero rows), so 2028 stays on the estimate.
        'announced' => [
            'MY' => [
                2026 => ['start' => '02-18', 'end' => '03-19'],
                2027 => ['start' => '02-07', 'end' => '03-08'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream endpoints and HTTP budget
    |--------------------------------------------------------------------------
    |
    | Live fetching happens only in queued jobs, scheduled prefetch, and the
    | preview endpoint. Timeouts stay short; failures degrade, never throw
    | onto the submit path.
    |
    */

    'http_timeout' => (int) env('PRAYER_HTTP_TIMEOUT', 3),

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Consecutive transport/429/5xx failures past the threshold open the
    | provider's circuit for the cooldown, short-circuiting live attempts
    | to ProviderUnavailable. Routine 404s (unpublished months) never trip
    | it. Fails open when the cache store itself is down.
    |
    */

    'breaker' => [
        'threshold' => (int) env('PRAYER_BREAKER_THRESHOLD', 5),
        'cooldown' => (int) env('PRAYER_BREAKER_COOLDOWN', 300),
    ],

    'jakim' => [
        'base_url' => env('PRAYER_JAKIM_BASE_URL', 'https://api.waktusolat.app'),
    ],

    'ummah' => [
        'base_url' => env('PRAYER_UMMAH_BASE_URL', 'https://ummahapi.com/api'),
    ],

    'aladhan' => [
        'base_url' => env('PRAYER_ALADHAN_BASE_URL', 'https://api.aladhan.com/v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Monthly payloads are immutable once published: cache until month end and
    | retain stale indefinitely as a fallback layer. Daily keys cover global
    | coordinate lookups. Negative entries stay short so unpublished months
    | never poison the stale layer.
    |
    */

    'cache' => [
        'store' => env('PRAYER_CACHE_STORE'),
        'prefix' => env('PRAYER_CACHE_PREFIX', 'prayer:v3'),
        'daily_ttl' => (int) env('PRAYER_CACHE_DAILY_TTL', 86400),
        'negative_ttl' => (int) env('PRAYER_CACHE_NEGATIVE_TTL', 300),
    ],

];
