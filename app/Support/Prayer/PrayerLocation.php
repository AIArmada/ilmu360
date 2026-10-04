<?php

declare(strict_types=1);

namespace App\Support\Prayer;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Institution;
use App\Models\Venue;
use App\Services\Prayer\DistrictZoneMatcher;

/**
 * Extracts prayer-resolution inputs from a package address.
 *
 * The address table carries denormalized `state`/`country` text columns that
 * shadow the `state()`/`country()` relations, so codes are read through
 * explicit relation queries instead of property access.
 *
 * `districtCandidates` climbs the Malaysian geography ladder — subdivision
 * literal, district area, mukim parent-walk, city — so the zone
 * resolver can fall back from finer to broader areas offline. Candidates
 * are only extracted when the provider flag is on; the flag-off submit
 * path keeps its current query cost.
 */
final class PrayerLocation
{
    /**
     * Area-assignment roles per country driving district candidates.
     * Countries sharing the package's generic role vocabulary need no row;
     * a country with bespoke roles slots in with one entry.
     *
     * @var array<string, array{district: string, subdivision: string}>
     */
    private const CANDIDATE_ROLES = [
        'default' => ['district' => 'administrative_district', 'subdivision' => 'administrative_subdivision'],
        'MY' => ['district' => 'administrative_district', 'subdivision' => 'administrative_subdivision'],
    ];

    private const MAX_PARENT_HOPS = 5;

    /**
     * Picks the event's real address: institution first (the ~90% case),
     * then venue, then the venue's owning institution (primary owner,
     * then oldest). Target resolvers pass exactly one of the two ids, so
     * the order only short-circuits the common path; a venue without its
     * own address borrows its owner's as an approximation rather than
     * collapsing to the country default.
     */
    public static function forTargets(?string $venueId, ?string $institutionId): ?Address
    {
        return self::withinOwnerContext(static fn (): ?Address => self::forTargetsQuery($venueId, $institutionId));
    }

    private static function forTargetsQuery(?string $venueId, ?string $institutionId): ?Address
    {
        if ($institutionId !== null && $institutionId !== '') {
            $address = Institution::query()->find($institutionId)?->primaryAddress();

            if ($address instanceof Address) {
                return $address;
            }
        }

        if ($venueId !== null && $venueId !== '') {
            $venue = Venue::query()->find($venueId);
            $address = $venue?->primaryAddress();

            if ($address instanceof Address) {
                return $address;
            }

            if ($venue instanceof Venue) {
                $owner = $venue->institutions()
                    ->reorder()
                    ->orderByDesc('institution_venue.is_primary')
                    ->oldest('institutions.id')
                    ->first();

                return $owner?->primaryAddress();
            }
        }

        return null;
    }

    /**
     * @return array{countryCode: string|null, latitude: float|null, longitude: float|null, stateCode: string|null, districtCandidates: list<string>}
     */
    public static function fromAddress(?Address $address, ?string $countryFallback = null): array
    {
        if (! $address instanceof Address) {
            return [
                'countryCode' => $countryFallback,
                'latitude' => null,
                'longitude' => null,
                'stateCode' => null,
                'districtCandidates' => [],
            ];
        }

        $stateCode = $address->state()->value('code');
        $countryCode = $countryFallback ?? $address->country()->value('iso2');
        $countryCode = is_string($countryCode) ? $countryCode : null;

        return [
            'countryCode' => $countryCode,
            'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
            'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
            'stateCode' => is_string($stateCode) ? $stateCode : null,
            'districtCandidates' => self::districtCandidates($address, $countryCode),
        ];
    }

    /**
     * @return list<string>
     */
    private static function districtCandidates(Address $address, ?string $countryCode): array
    {
        if (! config('prayer.enabled')) {
            return [];
        }

        $roles = $countryCode !== null
            ? (self::CANDIDATE_ROLES[strtoupper($countryCode)] ?? self::CANDIDATE_ROLES['default'])
            : self::CANDIDATE_ROLES['default'];

        return self::withinOwnerContext(static fn (): array => self::districtCandidatesWithinGlobalScope($address, $roles));
    }

    /**
     * @param  array{district: string, subdivision: string}  $roles
     * @return list<string>
     */
    private static function districtCandidatesWithinGlobalScope(Address $address, array $roles): array
    {
        $candidates = [];

        $subdivision = self::primaryArea($address, $roles['subdivision']);

        // Finer areas first: mukim literals appear verbatim in some zone
        // listings (`Mukim Chiku` under KTN01) and must outrank their
        // broader parent district (`Gua Musang` under KTN02).
        if ($subdivision instanceof AddressArea && is_string($subdivision->name) && trim($subdivision->name) !== '') {
            $candidates[] = trim($subdivision->name);
        }

        $district = self::primaryAreaName($address, $roles['district']);

        if ($district !== null) {
            $candidates[] = $district;
        }

        if ($subdivision instanceof AddressArea) {
            $parentDistrict = self::parentDistrictName($subdivision);

            if ($parentDistrict !== null) {
                $candidates[] = $parentDistrict;
            }
        }

        // The relation query runs only when a city is linked; text-only
        // addresses fall back to the denormalized column without a query.
        $city = $address->city_id !== null
            ? $address->city()->value('name')
            : $address->getAttribute('city');

        if (is_string($city) && trim($city) !== '') {
            $candidates[] = $city;
        }

        $candidates = array_filter(array_map(
            static fn (string $candidate): string => trim($candidate),
            $candidates,
        ));

        return array_values(array_unique($candidates));
    }

    private static function primaryAreaName(Address $address, string $role): ?string
    {
        $area = self::primaryArea($address, $role);

        return $area?->name !== null && trim((string) $area->name) !== ''
            ? trim((string) $area->name)
            : null;
    }

    private static function primaryArea(Address $address, string $role): ?AddressArea
    {
        $assignment = $address->areaAssignments()
            ->where('role', $role)
            ->where('is_primary', true)
            ->with('area')
            ->first();

        return $assignment?->area;
    }

    /**
     * Area assignments and address relations are owner-scoped reads; prayer
     * geography is public reference data over an already-visible event
     * address. A resolved caller context is respected as-is; only a bare
     * context (guest submits, API calls, jobs) escalates to explicit
     * global (same precedent as Event/MCP reads).
     */
    private static function withinOwnerContext(callable $callback): mixed
    {
        if (OwnerContext::resolve() !== null || OwnerContext::isExplicitGlobal()) {
            return $callback();
        }

        return OwnerContext::withOwner(null, $callback);
    }

    /**
     * Climbs `parent_id` from a mukim-level area to its district.
     */
    private static function parentDistrictName(AddressArea $area): ?string
    {
        $current = $area;

        for ($hop = 0; $hop < self::MAX_PARENT_HOPS; $hop++) {
            $parent = $current->parent;

            if (! $parent instanceof AddressArea) {
                return null;
            }

            if (in_array($parent->type, DistrictZoneMatcher::DISTRICT_TYPES, true)) {
                $name = trim((string) $parent->name);

                return $name !== '' ? $name : null;
            }

            $current = $parent;
        }

        return null;
    }
}
