<?php

declare(strict_types=1);

namespace App\Services\Prayer;

/**
 * Maps a district (or mukim/city) name to a prayer zone offline.
 *
 * Pure snapshot-driven matching — no HTTP, no cache. Defaults carry the
 * Malaysian snapshot, vocabulary, and scope map; other zone-based
 * countries slot in by injecting their own snapshot rows through the
 * constructor, with no subclassing unless their matching rules differ.
 */
final class DistrictZoneMatcher
{
    /**
     * District-like addressing area types. Anything else (mukim, bandar,
     * ...) only matches literally, e.g. `Mukim Chiku` inside KTN01.
     *
     * @var list<string>
     */
    public const DISTRICT_TYPES = ['district', 'minor_district'];

    /** @var array<string, array{state: string, districts: string}> */
    private array $zones;

    /** @var array<string, string> */
    private array $stateKeys;

    /** @var array<string, string> */
    private array $scopeMap;

    /** @var list<string> */
    private array $qualifiers;

    /** @var array<string, string> */
    private array $aliases;

    /**
     * @param  array<string, array{state: string, districts: string}>|null  $zones
     * @param  array<string, string>|null  $stateKeys  snapshot state name → state code
     * @param  array<string, string>|null  $scopeMap  state code → shared scope bucket
     * @param  list<string>|null  $qualifiers
     * @param  array<string, string>|null  $aliases
     */
    public function __construct(
        ?array $zones = null,
        ?array $stateKeys = null,
        ?array $scopeMap = null,
        ?array $qualifiers = null,
        ?array $aliases = null,
    ) {
        $this->zones = self::zoneRows($zones ?? self::configArray('prayer_zones.zones'));
        $this->stateKeys = self::stringMap($stateKeys ?? self::configArray('prayer_zones.zone_state_keys'));
        // Federal-territory codes share one snapshot state bucket.
        $this->scopeMap = self::stringMap($scopeMap ?? ['14' => '14', '15' => '14', '16' => '14']);
        $this->qualifiers = self::stringList($qualifiers ?? ['daerah', 'jajahan', 'mukim', 'bandar', 'pekan', 'kecil', 'bahagian']);
        // Common Malay toponym abbreviations, applied to both sides so
        // area data and zone text meet regardless of which abbreviates.
        $this->aliases = self::stringMap($aliases ?? ['sg' => 'sungai', 'kg' => 'kampung', 'tmn' => 'taman', 'jln' => 'jalan', 'bt' => 'batu']);
    }

    /**
     * @return array<mixed>
     */
    private static function configArray(string $key): array
    {
        $value = config($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<mixed>  $rows
     * @return array<string, array{state: string, districts: string}>
     */
    private static function zoneRows(array $rows): array
    {
        $zones = [];

        foreach ($rows as $code => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $zones[(string) $code] = [
                'state' => isset($entry['state']) && is_string($entry['state']) ? $entry['state'] : '',
                'districts' => isset($entry['districts']) && is_string($entry['districts']) ? $entry['districts'] : '',
            ];
        }

        return $zones;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, string>
     */
    private static function stringMap(array $value): array
    {
        $map = [];

        foreach ($value as $key => $item) {
            // Numeric-string keys arrive as ints; codes compare as strings.
            if (is_string($item)) {
                $map[(string) $key] = $item;
            }
        }

        return $map;
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private static function stringList(array $value): array
    {
        return array_values(array_filter($value, is_string(...)));
    }

    public function matchZone(?string $candidate, ?string $stateCode = null): ?string
    {
        $needle = $this->normalize($candidate);

        if ($needle === '') {
            return null;
        }

        $stateCode = $stateCode !== null && trim($stateCode) !== ''
            ? strtoupper(trim($stateCode))
            : null;

        $matches = [];

        foreach ($this->zones as $zone => $entry) {
            if ($stateCode !== null && ! $this->inStateScope((string) ($entry['state'] ?? ''), $stateCode)) {
                continue;
            }

            foreach ($this->splitDistricts((string) ($entry['districts'] ?? '')) as $district) {
                if ($this->normalize($district) === $needle) {
                    $matches[] = strtoupper((string) $zone);

                    break;
                }
            }
        }

        $matches = array_values(array_unique($matches));

        if ($matches === []) {
            return null;
        }

        // Ambiguity is never guessed: a candidate matching several zones
        // cannot identify one, scoped or not. Callers fall through to
        // broader defaults (state, then country) instead.
        if (count($matches) > 1) {
            return null;
        }

        return $matches[0];
    }

    private function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = mb_strtolower($value, 'UTF-8');
        // Parentheticals first. Single qualifiers stay part of the name
        // (`Bahagian Sandakan (Barat)` differs from `(Timur)`); locality
        // lists are stripped here because the zone side exposes each
        // locality as its own entry.
        $value = (string) preg_replace_callback('/\(([^)]*)\)/u', function (array $matches): string {
            // Group 1 always participates: even `()` captures an empty string.
            return $this->hasListSeparator($matches[1]) ? ' ' : ' '.$matches[1].' ';
        }, $value);

        foreach ($this->aliases as $short => $long) {
            $value = (string) preg_replace("/\\b{$short}\\.?\\b/u", $long, $value);
        }

        $qualifiers = implode('|', $this->qualifiers);
        $value = (string) preg_replace("/\\b(?:{$qualifiers})\\b/u", ' ', $value);
        $value = (string) preg_replace('/[^a-z0-9 ]/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    private function inStateScope(string $snapshotState, string $stateCode): bool
    {
        $key = $this->stateKeys[$snapshotState] ?? null;

        if (! is_string($key)) {
            return false;
        }

        return $key === ($this->scopeMap[$stateCode] ?? $stateCode);
    }

    private function hasListSeparator(string $inner): bool
    {
        return preg_match('/\s+dan\s+|,/iu', $inner) === 1;
    }

    /**
     * @return list<string>
     */
    private function splitDistricts(string $districts): array
    {
        $entries = [];

        // Split on commas outside parentheses so locality lists stay whole.
        foreach (preg_split('/,(?![^(]*\))/', $districts) ?: [] as $chunk) {
            $chunk = trim((string) $chunk);

            if ($chunk === '') {
                continue;
            }

            preg_match_all('/\(([^)]*)\)/u', $chunk, $parentheticals);
            $base = trim((string) preg_replace('/\([^)]*\)/u', ' ', $chunk));
            $qualifiers = [];

            foreach ($parentheticals[1] as $inner) {
                $inner = trim((string) $inner);

                if ($inner === '') {
                    continue;
                }

                if ($this->hasListSeparator($inner)) {
                    // Locality list: every item belongs to the zone
                    // (`Mukim Rompin, Mukim Endau, ...`).
                    foreach (preg_split('/\s+dan\s+|,/iu', $inner) ?: [] as $locality) {
                        $locality = trim((string) $locality);

                        if ($locality !== '') {
                            $entries[] = $locality;
                        }
                    }

                    continue;
                }

                $qualifiers[] = $inner;
                // Standalone locality (`Zon Khas (Kampung Patarikan)`).
                $entries[] = $inner;
            }

            if ($base === '') {
                continue;
            }

            // Island pairs share one zone: `Pulau Aur dan Pulau Pemanggil`.
            $parts = preg_split('/\s+dan\s+/iu', $base) ?: [];

            foreach ($parts as $part) {
                $part = trim((string) $part);

                if ($part === '') {
                    continue;
                }

                if ($qualifiers === []) {
                    $entries[] = $part;

                    continue;
                }

                // Qualified division (`Bahagian Sandakan (Barat)`): the
                // qualifier attaches to the base, and the bare base is
                // deliberately NOT added — a qualified name alone cannot
                // identify one zone.
                foreach ($qualifiers as $qualifier) {
                    $entries[] = "{$part} {$qualifier}";
                }
            }
        }

        return $entries;
    }
}
