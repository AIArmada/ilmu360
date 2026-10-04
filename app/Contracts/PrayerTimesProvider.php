<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Services\Prayer\ProviderUnavailable;

/**
 * One upstream prayer-times source (JAKIM mirror, Ummah, Aladhan).
 *
 * Providers return prayer ANCHORS only; callers apply reference offsets.
 * Implementations must be stateless and Octane-safe (no static request state).
 */
interface PrayerTimesProvider
{
    /**
     * Stable key for provenance, cache keys, and registry ordering.
     */
    public function key(): string;

    public function supports(string $countryCode): bool;

    /**
     * True when the provider resolves from the query zone (not coords).
     * Zone-bound providers serve zone-month lookups only; their results
     * must never fill coordinate-keyed daily cells.
     */
    public function requiresZone(): bool;

    /**
     * Provider-specific effective calculation settings for daily cache
     * identity (e.g. `ummah:MuslimWorldLeague:Shafi`, `aladhan:17:Shafi`).
     * Must reflect every setting that changes fetched clocks so config
     * edits invalidate cached days instead of reusing stale math.
     */
    public function cacheIdentitySegment(PrayerQuery $query): string;

    /**
     * Whether a stored monthly source string was computed under this
     * provider's CURRENT effective settings for the country. Monthly
     * cache keys carry no per-provider identity (mixed-source months
     * cannot key per provider), so the monthly reader asks every
     * provider and prunes on a single veto: return false only for
     * sources this provider owns that no longer match, true for
     * anything else (including other providers' sources and
     * setting-free sources). Coordinates and timezones prune via
     * the calc fingerprint, not here.
     */
    public function isSourceCurrent(string $source, string $countryCode): bool;

    /**
     * Canonical calculation-input fingerprint for this query (coords,
     * timezone, effective method/madhab), stamped onto calculated rows at
     * build time and re-checked by the monthly reader: a stored row whose
     * fingerprint no longer matches today's canonical inputs reads as a
     * gap. Null when this provider's rows are authoritative
     * (coordinate- and setting-independent) and must never prune on
     * calc inputs.
     */
    public function calcFingerprint(PrayerQuery $query): ?string;

    /**
     * @throws ProviderUnavailable
     */
    public function dailyPrayers(PrayerQuery $query): PrayerTimesDTO;
}
