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
     * @return array{slugToId: array<string, string>, idToSlug: array<string, string>, options: array<string, string>}
     */
    public function localityMaps(?string $stateId, ?string $countryId): array
    {
        return $this->mapsFor(
            'localities:'.($stateId ?? 'any').':'.($countryId ?? 'any'),
            static fn (): array => SharedFormSchema::areaOptionsForRole($countryId, 'postal_locality', $stateId),
            static fn (): array => SharedFormSchema::areaSlugsForRole($countryId, 'postal_locality', $stateId),
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
            $structural = array_map(
                static fn (AddressLevelDefinition $level): ?string => $level->assignmentRole,
                $this->administrativeAreaLevels($countryId),
            );

            // The two slots show the two deepest area levels: the finest
            // granularity the provider offers and its parent, so the cascade
            // always narrows to the smallest area. A lone level anchors the
            // first slot with the state as its parent.
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
     * area level with an assignment role. Levels without a role cannot
     * back a filter, so they are skipped.
     *
     * @return list<AddressLevelDefinition>
     */
    private function administrativeAreaLevels(string $countryId): array
    {
        $hierarchies = app(CountryAddressProfileResolver::class)->hierarchies($countryId);
        $selected = null;

        foreach ($hierarchies as $hierarchy) {
            if ($hierarchy->key === 'administrative') {
                $selected = $hierarchy;

                break;
            }
        }

        if ($selected === null) {
            foreach ($hierarchies as $hierarchy) {
                foreach ($hierarchy->levels as $level) {
                    if ($level->kind === 'area' && $level->assignmentRole !== null) {
                        $selected = $hierarchy;

                        break 2;
                    }
                }
            }
        }

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
