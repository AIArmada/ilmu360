<?php

declare(strict_types=1);

namespace App\Support\Location;

use AIArmada\Addressing\Data\AddressLevelDefinition;
use AIArmada\Addressing\Support\CountryAddressProfileResolver;
use App\Forms\SharedFormSchema;
use App\Support\Cache\SelectionCatalogCache;
use Illuminate\Support\Str;

/**
 * Bidirectional maps between friendly location slugs (URL segments) and
 * package geography IDs.
 *
 * Slugs are scoped exactly like the filter dropdowns: countries globally,
 * states per country, cities per state, areas per the profile scope that
 * lists them. Every map is derived from the same cached option sources the
 * dropdowns use, so slug resolution adds no queries on top of rendering the
 * filters — except the narrow area-slug pluck, which shares the address
 * catalog cache.
 *
 * Sibling rows may share a name (and areas may share a stored slug), so maps
 * assign deterministic `-2`, `-3` suffixes ordered by (name, id). Both
 * directions always come from the same map, so a slug round-trips.
 */
final class LocationSlugResolver
{
    private static ?string $cacheScope = null;

    /**
     * @var array<string, array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}>
     */
    private static array $maps = [];

    /** @var array<string, array{?string, ?string}> */
    private static array $slotRoles = [];

    public function __construct(private readonly SelectionCatalogCache $catalogCache) {}

    /**
     * @return array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}
     */
    public function countryMaps(): array
    {
        return $this->mapsFor(
            'countries',
            fn (): array => $this->catalogCache->countryOptions(),
        );
    }

    /**
     * @return array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}
     */
    public function stateMaps(string $countryId): array
    {
        if ($this->providerOmitsStateLevel($countryId)) {
            return ['slugToId' => [], 'idToSlug' => [], 'options' => []];
        }

        return $this->mapsFor(
            "states:{$countryId}",
            static fn (): array => SharedFormSchema::stateOptionsForCountry($countryId),
        );
    }

    /**
     * ID-keyed state options for select fields.
     *
     * Unlike stateMaps()['options'] (slug-keyed for friendly URLs), these map
     * IDs to names. Empty when no country is set or the country's provider
     * defines no state-kind level.
     *
     * @return array<int|string, string>
     */
    public function stateOptionsForCountry(?string $countryId): array
    {
        if ($countryId === null) {
            return [];
        }

        if ($this->providerOmitsStateLevel($countryId)) {
            return [];
        }

        return SharedFormSchema::stateOptionsForCountry($countryId);
    }

    /**
     * @return array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}
     */
    public function cityMaps(?string $stateId, ?string $countryId): array
    {
        return $this->mapsFor(
            'cities:'.($stateId ?? 'any').':'.($countryId ?? 'any'),
            static fn (): array => SharedFormSchema::cityOptionsForState($stateId, $countryId),
        );
    }

    /**
     * Locality maps scoped through addressing's parent resolution.
     *
     * The parent is the package-resolved scope (a picked district where
     * links prove the narrowing, else the state root), so these maps list
     * exactly the options the dropdown offers.
     *
     * @param  array<string, ?string>  $areaIds
     * @return array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}
     */
    public function localityMaps(?string $stateId, ?string $countryId, array $areaIds = []): array
    {
        return $this->areaMapsForRole(
            $countryId,
            'postal_locality',
            $this->areaParentIdForRole($countryId, 'postal_locality', $stateId, $areaIds),
        );
    }

    /**
     * @return array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}
     */
    public function districtMaps(?string $stateId, ?string $countryId): array
    {
        $role = $this->districtRoleForCountry($countryId);

        if ($role === null) {
            return ['slugToId' => [], 'idToSlug' => [], 'options' => []];
        }

        return $this->mapsFor(
            "districts:{$countryId}:{$stateId}",
            static fn (): array => SharedFormSchema::areaOptionsForRole($countryId, $role, $stateId),
            static fn (): array => SharedFormSchema::areaSlugsForRole($countryId, $role, $stateId),
        );
    }

    /**
     * @return array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}
     */
    public function subdivisionMaps(?string $stateId, ?string $districtId, ?string $countryId): array
    {
        $role = $this->subdivisionRoleForCountry($countryId);

        if ($role === null) {
            return ['slugToId' => [], 'idToSlug' => [], 'options' => []];
        }

        return $this->mapsFor(
            "subdivisions:{$countryId}:{$stateId}:{$districtId}",
            static fn (): array => SharedFormSchema::areaOptionsForRole($countryId, $role, $districtId ?? $stateId),
            static fn (): array => SharedFormSchema::areaSlugsForRole($countryId, $role, $districtId ?? $stateId),
        );
    }

    /**
     * @return list<AddressLevelDefinition>
     */
    public function areaLevelsForCountry(?string $countryId): array
    {
        return $countryId === null ? [] : $this->administrativeAreaLevels($countryId);
    }

    /**
     * @return list<string>
     */
    public function areaRolesForCountry(?string $countryId): array
    {
        return array_values(array_filter(array_map(
            static fn (AddressLevelDefinition $level): ?string => $level->assignmentRole,
            $this->areaLevelsForCountry($countryId),
        )));
    }

    /**
     * @return array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}
     */
    public function areaMapsForRole(?string $countryId, string $role, ?string $parentId = null): array
    {
        if ($countryId === null) {
            return ['slugToId' => [], 'idToSlug' => [], 'options' => []];
        }

        return $this->mapsFor(
            "areas:{$countryId}:{$role}:".($parentId ?? 'root'),
            static fn (): array => SharedFormSchema::areaOptionsForRole($countryId, $role, $parentId),
            static fn (): array => SharedFormSchema::areaSlugsForRole($countryId, $role, $parentId),
        );
    }

    /**
     * Resolve a role's provider parent through addressing.
     *
     * The cascade truth (declared chain, link-proven narrowing, state
     * fallback) lives in the package; the probe keeps the flip consistent
     * with the option maps the dropdowns render.
     *
     * @param  array<string, ?string>  $areaIds
     */
    public function areaParentIdForRole(?string $countryId, string $role, ?string $stateId, array $areaIds = []): ?string
    {
        if ($countryId === null) {
            return null;
        }

        return app(CountryAddressProfileResolver::class)->parentAreaIdForRole(
            $countryId,
            $role,
            $stateId,
            $areaIds,
            fn (string $probeRole, string $probeParentId): bool => $this->areaMapsForRole($countryId, $probeRole, $probeParentId)['options'] !== [],
        );
    }

    /**
     * Roles to clear when a role changes, per addressing.
     *
     * @return list<string>
     */
    public function areaSuccessorRolesForCountry(?string $countryId, string $role): array
    {
        if ($countryId === null) {
            return [];
        }

        return app(CountryAddressProfileResolver::class)->successorRoles($countryId, $role);
    }

    /**
     * Role gating a filter: the declared parent, except region-parented
     * roles gate on an explicitly refinedBy role or the nearest preceding
     * area level where addressing proves the narrowing is structural in
     * the selected state.
     */
    public function areaEffectiveParentRoleForCountry(?string $countryId, string $role, ?string $stateId): ?string
    {
        if ($countryId === null) {
            return null;
        }

        $parent = app(CountryAddressProfileResolver::class)->effectiveParentLevel($countryId, $role, $stateId);

        if (! $parent instanceof AddressLevelDefinition) {
            return null;
        }

        return $parent->kind === 'state' ? 'state' : $parent->assignmentRole;
    }

    /**
     * @param  array<string, ?string>  $slugs
     * @return array<string, ?string>
     */
    public function areaIdsForSlugs(?string $countryId, ?string $stateId, array $slugs): array
    {
        $ids = [];

        foreach ($this->areaRolesForCountry($countryId) as $role) {
            $slug = self::cleanSlug($slugs[$role] ?? null);

            if ($slug === null) {
                $ids[$role] = null;

                continue;
            }

            $parentId = $this->areaParentIdForRole($countryId, $role, $stateId, $ids);
            $ids[$role] = $this->areaMapsForRole($countryId, $role, $parentId)['slugToId'][$slug] ?? null;
        }

        return $ids;
    }

    /**
     * Area role behind the first cascade slot ("district") for a country.
     *
     * Null when the country has no provider area levels, in which case the
     * slot renders nothing.
     */
    public function districtRoleForCountry(?string $countryId): ?string
    {
        return $this->slotRolesForCountry($countryId)[0];
    }

    /**
     * Area role behind the second cascade slot ("subdivision") for a country.
     *
     * Null when the country has fewer than two provider area levels, in
     * which case the slot renders nothing.
     */
    public function subdivisionRoleForCountry(?string $countryId): ?string
    {
        return $this->slotRolesForCountry($countryId)[1];
    }

    /**
     * Resolve filter slugs to package IDs, following the dropdown cascade:
     * each level resolves within its parent's scope, and anything unknown
     * resolves to null (filter ignored), like the old unknown UUIDs.
     *
     * Shared by the directory filter components and their results children
     * so both resolve slugs through exactly the same maps.
     *
     * @param  array{search?: ?string, country?: ?string, state?: ?string, city?: ?string, locality?: ?string, district?: ?string, subdivision?: ?string, areas?: array<string, ?string>}  $slugs
     * @return array{country_id: ?string, state_id: ?string, city_id: ?string, locality_id: ?string, district_id: ?string, subdivision_id: ?string, area_ids: array<string, ?string>}
     */
    public function idsForSlugs(array $slugs): array
    {
        $countrySlug = self::cleanSlug($slugs['country'] ?? null);
        $countryId = $countrySlug !== null
            ? ($this->countryMaps()['slugToId'][$countrySlug] ?? null)
            : null;

        $stateSlug = self::cleanSlug($slugs['state'] ?? null);
        $stateId = $countryId !== null && $stateSlug !== null
            ? ($this->stateMaps($countryId)['slugToId'][$stateSlug] ?? null)
            : null;

        $citySlug = self::cleanSlug($slugs['city'] ?? null);
        $cityId = $citySlug !== null
            ? ($this->cityMaps($stateId, $countryId)['slugToId'][$citySlug] ?? null)
            : null;

        $areaSlugs = is_array($slugs['areas'] ?? null) ? $slugs['areas'] : [];
        $legacyRoles = $this->slotRolesForCountry($countryId);

        if (($legacyDistrict = self::cleanSlug($slugs['district'] ?? null)) !== null && $legacyRoles[0] !== null) {
            $areaSlugs[$legacyRoles[0]] ??= $legacyDistrict;
        }

        if (($legacySubdivision = self::cleanSlug($slugs['subdivision'] ?? null)) !== null && $legacyRoles[1] !== null) {
            $areaSlugs[$legacyRoles[1]] ??= $legacySubdivision;
        }

        $areaIds = $this->areaIdsForSlugs($countryId, $stateId, $areaSlugs);

        $localitySlug = self::cleanSlug($slugs['locality'] ?? null);
        $localityId = $localitySlug !== null
            ? ($this->localityMaps($stateId, $countryId, $areaIds)['slugToId'][$localitySlug] ?? null)
            : null;

        $districtRole = $legacyRoles[0];
        $subdivisionRole = $legacyRoles[1];
        $districtId = $districtRole !== null ? ($areaIds[$districtRole] ?? null) : null;
        $subdivisionId = $subdivisionRole !== null ? ($areaIds[$subdivisionRole] ?? null) : null;

        return [
            'country_id' => $countryId,
            'state_id' => $stateId,
            'city_id' => $cityId,
            'locality_id' => $localityId,
            'district_id' => $districtId,
            'subdivision_id' => $subdivisionId,
            'area_ids' => $areaIds,
        ];
    }

    /** @return array{?string, ?string} */
    private function slotRolesForCountry(?string $countryId): array
    {
        self::ensureCacheScope();

        $key = $countryId ?? 'any';

        if (isset(self::$slotRoles[$key])) {
            return self::$slotRoles[$key];
        }

        $roles = [null, null];

        if ($countryId !== null) {
            $levels = $this->areaLevelsForCountry($countryId);

            // Nested hierarchies need the first linked pair so the second
            // slot can be scoped by the first (for example, Indonesia's
            // regency → district cascade). Flat hierarchies keep the two
            // deepest slots, preserving the existing Malaysia behavior.
            $structuralLevels = count($levels) >= 2
                && $levels[1]->parentKey === $levels[0]->key
                ? array_slice($levels, 0, 2)
                : array_slice($levels, -2);

            $structural = array_map(
                static fn (AddressLevelDefinition $level): ?string => $level->assignmentRole,
                $structuralLevels,
            );
            $count = count($structural);

            if ($count >= 2) {
                $roles = [$structural[$count - 2], $structural[$count - 1]];
            } elseif ($count === 1) {
                $roles = [$structural[0], null];
            }
        }

        return self::$slotRoles[$key] = $roles;
    }

    /**
     * Area-bearing levels of the country's administrative hierarchy.
     *
     * Prefers the hierarchy keyed 'administrative' (the package convention
     * all current providers follow), else the first hierarchy holding an
     * area level. Levels without a role cannot back a filter, so they are
     * skipped.
     *
     * @return list<AddressLevelDefinition>
     */
    private function administrativeAreaLevels(string $countryId): array
    {
        $resolver = app(CountryAddressProfileResolver::class);
        $selected = $resolver->hierarchy($countryId, 'administrative')
            ?? $resolver->firstAreaHierarchy($countryId);

        if ($selected === null) {
            return [];
        }

        return array_values(array_filter(
            $selected->levels,
            static fn (AddressLevelDefinition $level): bool => $level->kind === 'area' && $level->assignmentRole !== null,
        ));
    }

    /**
     * Whether the country's provider exists but defines no state-kind level.
     *
     * The provider is authoritative about which levels exist: such countries
     * (e.g. Singapore's CDC districts, which sit outside both hierarchies)
     * offer area filters directly instead of a State row.
     */
    private function providerOmitsStateLevel(string $countryId): bool
    {
        $resolver = app(CountryAddressProfileResolver::class);

        return $resolver->hierarchies($countryId) !== []
            && $resolver->stateLevel($countryId) === null;
    }

    public static function cleanSlug(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $cleaned = Str::slug(trim($value));

        return $cleaned === '' ? null : $cleaned;
    }

    /**
     * @param  callable(): array<int|string, string>  $optionsResolver  id => name, the dropdown source
     * @param  (callable(): array<int|string, string|null>)|null  $slugResolver  id => stored slug, when available
     * @return array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}
     */
    private function mapsFor(string $key, callable $optionsResolver, ?callable $slugResolver = null): array
    {
        self::ensureCacheScope();

        if (isset(self::$maps[$key])) {
            return self::$maps[$key];
        }

        $options = $optionsResolver();
        $storedSlugs = $slugResolver !== null ? $slugResolver() : [];

        $ids = array_keys($options);
        usort($ids, static fn (mixed $a, mixed $b): int => [(string) $options[$a], (string) $a] <=> [(string) $options[$b], (string) $b]);

        $slugToId = [];
        $idToSlug = [];
        $slugOptions = [];
        $usedSlugs = [];

        foreach ($ids as $id) {
            $stored = $storedSlugs[$id] ?? null;
            $base = is_string($stored) && trim($stored) !== ''
                ? strtolower(trim($stored))
                : Str::slug((string) $options[$id]);

            if ($base === '') {
                $base = 'loc';
            }

            $slug = $base;
            $suffix = 2;

            while (isset($usedSlugs[$slug])) {
                $slug = "{$base}-{$suffix}";
                $suffix++;
            }

            $usedSlugs[$slug] = true;
            $slugToId[$slug] = (string) $id;
            $idToSlug[(string) $id] = $slug;
            $slugOptions[$slug] = (string) $options[$id];
        }

        return self::$maps[$key] = [
            'slugToId' => $slugToId,
            'idToSlug' => $idToSlug,
            'options' => $slugOptions,
        ];
    }

    /**
     * Reset the per-request memo. Modelled on SharedFormSchema::ensureCacheScope()
     * so Octane workers do not leak one request's maps into the next.
     */
    private static function ensureCacheScope(): void
    {
        $scope = spl_object_hash(app()).':'.(app()->bound('request') ? spl_object_hash(request()) : 'console');

        if (self::$cacheScope === $scope) {
            return;
        }

        self::$cacheScope = $scope;
        self::$maps = [];
        self::$slotRoles = [];
    }
}
