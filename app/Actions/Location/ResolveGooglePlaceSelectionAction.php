<?php

namespace App\Actions\Location;

use AIArmada\Addressing\Data\AddressHierarchyDefinition;
use AIArmada\Addressing\Data\AddressLevelDefinition;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaName;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\PostalCode;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaHierarchyResolver;
use AIArmada\Addressing\Support\AddressAreaStateBridge;
use AIArmada\Addressing\Support\CountryAddressProfileResolver;
use App\Support\Location\GooglePlaceComponentMapper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveGooglePlaceSelectionAction
{
    use AsAction;

    public function __construct(
        private readonly NormalizeGoogleMapsInputAction $normalizeGoogleMapsInputAction,
        private readonly AddressAreaHierarchyResolver $addressAreaHierarchyResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     country_id: string|null,
     *     state_id: string|null,
     *     city_id: string|null,
     *     area_assignments: array<string, string>,
     *     line1: string|null,
     *     line2: string|null,
     *     postcode: string|null,
     *     state: string|null,
     *     city: string|null,
     *     google_maps_url: string|null,
     *     provider_place_id: string|null,
     *     google_display_name: string|null,
     *     latitude: float|null,
     *     longitude: float|null,
     *     google_resolution_source: string|null,
     *     google_resolution_status: 'resolved'|'partial'|'unresolved',
     *     google_resolution_fingerprint: string|null,
     *     google_resolution_message: string|null
     * }
     */
    public function handle(array $payload): array
    {
        /** @var list<array{longText: string|null, shortText: string|null, types: list<string>}> $components */
        $components = $this->normalizeAddressComponents($payload['addressComponents'] ?? []);
        $country = $this->resolveCountry($components);
        $countryId = ($country instanceof AddressCountry ? (string) $country->getKey() : null)
            ?? $this->uuidValue($payload['fallbackCountryId'] ?? null);
        $countryCode = $this->resolveCountryCode($country, $countryId);

        $stateName = $this->componentValue($components, ['administrative_area_level_1']);
        $cityName = $this->firstFilled([
            $this->componentValue($components, ['locality']),
            $this->componentValue($components, ['postal_town']),
        ]);
        $postcode = $this->componentValue($components, ['postal_code']);

        // Provisional root under the legacy type scope discovers an omitted
        // country before the profile loads; the authoritative match below
        // re-resolves under the profile's own state types.
        $areaTreeRoot = $this->resolveArea($stateName, $countryId, null, ['state', 'wilayah_persekutuan'], null, $countryCode);
        $countryId = $this->preferredString([$areaTreeRoot?->country_id, $countryId]);
        $countryCode ??= $this->resolveCountryCode($country, $countryId);

        $profiles = app(CountryAddressProfileResolver::class);
        $hierarchies = $countryId !== null ? $profiles->hierarchies($countryId) : [];
        $stateLevel = $countryId !== null ? $profiles->stateLevel($countryId) : null;
        $stateTypes = $this->stateAreaTypes($hierarchies, $stateLevel);

        if ($stateTypes !== []
            && (! $areaTreeRoot instanceof AddressArea || ! in_array($areaTreeRoot->type, $stateTypes, true))) {
            $areaTreeRoot = $this->resolveArea($stateName, $countryId, null, $stateTypes, null, $countryCode)
                ?? $areaTreeRoot;
        }

        if ($stateTypes === []) {
            $areaTreeRoot = null;
        }

        $countryId = $this->preferredString([$areaTreeRoot?->country_id, $countryId]);
        $stateId = $this->resolveStateId($stateName, $countryId, $areaTreeRoot, $countryCode);
        $areaTreeRootId = AddressAreaStateBridge::areaIdForState($stateId, 'administrative');
        $areaTreeRoot = $areaTreeRootId !== null
            ? AddressArea::query()->find($areaTreeRootId)
            : $areaTreeRoot;

        if (! $areaTreeRoot instanceof AddressArea) {
            $areaTreeRoot = null;
        }

        /** @var array<string, array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}> $resolved */
        $resolved = [];
        /** @var array<string, list<string>> $resolvedRolesByHierarchy */
        $resolvedRolesByHierarchy = [];

        $this->resolveStructuralLevels($hierarchies, $components, $countryId, $countryCode, $areaTreeRoot, $resolved, $resolvedRolesByHierarchy);
        $this->resolvePostalLevels($hierarchies, $components, $postcode, $countryId, $countryCode, $areaTreeRoot, $resolved, $resolvedRolesByHierarchy);
        $this->recoverAncestors($hierarchies, $resolved, $resolvedRolesByHierarchy);

        if (! $areaTreeRoot instanceof AddressArea) {
            $areaTreeRoot = $this->recoverAreaTreeRoot($hierarchies, $stateLevel, $stateTypes, $resolved);
        }

        $countryCandidates = [$areaTreeRoot?->country_id];

        foreach ($this->resolvedEntriesInOrder($hierarchies, $resolved) as $entry) {
            $countryCandidates[] = $entry['area']->country_id;
        }

        $countryCandidates[] = $countryId;
        $countryId = $this->preferredString($countryCandidates);

        $stateId ??= $this->resolveStateId($stateName, $countryId, $areaTreeRoot, $countryCode);
        $stateId ??= $this->recoverStateId($hierarchies, $resolved);
        $cityId = $this->resolveCityId($cityName, $stateId, $countryId, $countryCode);

        $assignments = [];

        foreach ($resolved as $role => $entry) {
            $assignments[$role] = (string) $entry['area']->getKey();
        }

        $lat = $this->numericValue(Arr::get($payload, 'location.lat'));
        $lng = $this->numericValue(Arr::get($payload, 'location.lng'));
        $googleMapsState = $this->normalizeGoogleMapsInputAction->handle([
            'google_maps_url' => $this->stringValue($payload['googleMapsURI'] ?? null),
            'google_place_id' => $this->stringValue($payload['placeId'] ?? $payload['id'] ?? null),
            'google_display_name' => $this->displayNameValue($payload['displayName'] ?? null),
            'lat' => $lat,
            'lng' => $lng,
            'google_resolution_source' => 'picker',
            'google_resolution_status' => 'resolved',
        ]);

        return [
            'country_id' => $countryId,
            'state_id' => $stateId,
            'city_id' => $cityId,
            'area_assignments' => $this->orderAssignments($hierarchies, $assignments),
            'line1' => $this->resolveLine1($components),
            'line2' => $this->resolveLine2($components),
            'postcode' => $postcode,
            'state' => $stateId === null ? $stateName : null,
            'city' => $cityId === null ? $cityName : null,
            'google_maps_url' => $googleMapsState['google_maps_url'],
            'provider_place_id' => $googleMapsState['google_place_id'],
            'google_display_name' => $googleMapsState['google_display_name'],
            'latitude' => $googleMapsState['lat'],
            'longitude' => $googleMapsState['lng'],
            'google_resolution_source' => $googleMapsState['google_resolution_source'],
            'google_resolution_status' => $googleMapsState['google_resolution_status'],
            'google_resolution_fingerprint' => $googleMapsState['google_resolution_fingerprint'],
            'google_resolution_message' => $googleMapsState['google_resolution_message'],
        ];
    }

    /**
     * Resolve every non-postal area level in hierarchy order.
     *
     * Each level matches its ordered Google candidates below the deepest
     * already-resolved area (or the state-anchored root), then anywhere below
     * the root when Google omits an intermediate level.
     *
     * @param  list<AddressHierarchyDefinition>  $hierarchies
     * @param  list<array{longText: string|null, shortText: string|null, types: list<string>}>  $components
     * @param  array<string, array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}>  $resolved
     * @param  array<string, list<string>>  $resolvedRolesByHierarchy
     */
    private function resolveStructuralLevels(
        array $hierarchies,
        array $components,
        ?string $countryId,
        ?string $countryCode,
        ?AddressArea $areaTreeRoot,
        array &$resolved,
        array &$resolvedRolesByHierarchy,
    ): void {
        foreach ($hierarchies as $hierarchy) {
            if ($hierarchy->key === 'postal') {
                continue;
            }

            $areaDepth = -1;

            foreach ($hierarchy->levels as $level) {
                if ($level->kind !== 'area') {
                    continue;
                }

                $areaDepth++;
                $role = CountryAddressProfileResolver::roleForLevel($hierarchy, $level);
                $types = $this->levelTypes($level);

                if ($types === []) {
                    continue;
                }

                $names = $this->orderedCandidateNames(
                    $components,
                    GooglePlaceComponentMapper::componentTypesForLevel($hierarchy, $areaDepth),
                );

                if ($names === []) {
                    continue;
                }

                $area = $this->matchLevel(
                    $names,
                    $countryId,
                    $countryCode,
                    $this->deepestResolvedId($hierarchy->key, $resolved, $resolvedRolesByHierarchy)
                        ?? ($areaTreeRoot instanceof AddressArea ? (string) $areaTreeRoot->getKey() : null),
                    $areaTreeRoot,
                    $hierarchy,
                    $level,
                );

                if ($area instanceof AddressArea) {
                    $resolved[$role] = ['hierarchy' => $hierarchy, 'level' => $level, 'area' => $area];
                    $resolvedRolesByHierarchy[$hierarchy->key][] = $role;
                }
            }
        }
    }

    /**
     * Resolve postal levels from the postcode, then by name as a fallback.
     *
     * Postcode-linked areas resolve regardless of the structural match, while
     * name matching only runs when no structural area resolved — a postal
     * locality is fallback granularity, not a second answer alongside one.
     *
     * @param  list<AddressHierarchyDefinition>  $hierarchies
     * @param  list<array{longText: string|null, shortText: string|null, types: list<string>}>  $components
     * @param  array<string, array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}>  $resolved
     * @param  array<string, list<string>>  $resolvedRolesByHierarchy
     */
    private function resolvePostalLevels(
        array $hierarchies,
        array $components,
        ?string $postcode,
        ?string $countryId,
        ?string $countryCode,
        ?AddressArea $areaTreeRoot,
        array &$resolved,
        array &$resolvedRolesByHierarchy,
    ): void {
        $structuralResolved = false;

        foreach ($resolved as $entry) {
            if ($entry['hierarchy']->key !== 'postal') {
                $structuralResolved = true;

                break;
            }
        }

        foreach ($hierarchies as $hierarchy) {
            if ($hierarchy->key !== 'postal') {
                continue;
            }

            $this->resolvePostalFromPostcode($postcode, $countryId, $countryCode, $hierarchy, $resolved, $resolvedRolesByHierarchy);

            if ($structuralResolved) {
                continue;
            }

            $areaDepth = -1;

            foreach ($hierarchy->levels as $level) {
                if ($level->kind !== 'area') {
                    continue;
                }

                $areaDepth++;
                $role = CountryAddressProfileResolver::roleForLevel($hierarchy, $level);

                if (isset($resolved[$role])) {
                    continue;
                }

                $types = $this->levelTypes($level);

                if ($types === []) {
                    continue;
                }

                $names = $this->orderedCandidateNames(
                    $components,
                    GooglePlaceComponentMapper::componentTypesForLevel($hierarchy, $areaDepth),
                );

                if ($names === []) {
                    continue;
                }

                $area = $this->matchLevel(
                    $names,
                    $countryId,
                    $countryCode,
                    $this->deepestResolvedId($hierarchy->key, $resolved, $resolvedRolesByHierarchy)
                        ?? ($areaTreeRoot instanceof AddressArea ? (string) $areaTreeRoot->getKey() : null),
                    $areaTreeRoot,
                    $hierarchy,
                    $level,
                );

                if ($area instanceof AddressArea) {
                    $resolved[$role] = ['hierarchy' => $hierarchy, 'level' => $level, 'area' => $area];
                    $resolvedRolesByHierarchy[$hierarchy->key][] = $role;
                }
            }
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function matchLevel(
        array $names,
        ?string $countryId,
        ?string $countryCode,
        ?string $parentId,
        ?AddressArea $areaTreeRoot,
        AddressHierarchyDefinition $hierarchy,
        AddressLevelDefinition $level,
    ): ?AddressArea {
        $types = $this->levelTypes($level);
        $levels = $this->levelLevels($level);

        foreach ($names as $name) {
            $area = $this->resolveArea($name, $countryId, $parentId, $types, $levels, $countryCode);

            if ($area instanceof AddressArea) {
                return $area;
            }
        }

        if ($areaTreeRoot instanceof AddressArea) {
            $hierarchyType = $level->hierarchyType ?? $hierarchy->key;

            foreach ($names as $name) {
                $candidate = $this->addressAreaHierarchyResolver->resolveWithinHierarchy(
                    $name,
                    $countryId,
                    (string) $areaTreeRoot->getKey(),
                    $hierarchyType,
                    $types,
                );

                if ($candidate instanceof AddressArea && $this->areaMatchesLevel($candidate, $level)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Link postcode-registered areas to their postal levels.
     *
     * Fully provider-driven: any country with imported postcodes resolves
     * here, with ties and unknown codes degrading to no assignment.
     *
     * @param  array<string, array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}>  $resolved
     * @param  array<string, list<string>>  $resolvedRolesByHierarchy
     */
    private function resolvePostalFromPostcode(
        ?string $postcode,
        ?string $countryId,
        ?string $countryCode,
        AddressHierarchyDefinition $hierarchy,
        array &$resolved,
        array &$resolvedRolesByHierarchy,
    ): void {
        $code = is_string($postcode) ? mb_strtoupper(mb_trim($postcode)) : '';

        if ($code === '' || $countryCode === null) {
            return;
        }

        $areas = PostalCode::query()
            ->where('country_code', $countryCode)
            ->where('code', $code)
            ->where('is_active', true)
            ->with('areas')
            ->get()
            ->flatMap(static fn (PostalCode $postalCode) => $postalCode->areas)
            ->filter(static fn (AddressArea $area): bool => $area->is_active !== false)
            ->filter(fn (AddressArea $area): bool => $countryId === null
                || (string) $area->country_id === $countryId)
            ->unique(static fn (AddressArea $area): string => (string) $area->getKey())
            ->values();

        $primary = $areas->filter(static function (AddressArea $area): bool {
            $pivot = $area->getRelation('pivot');

            return $pivot !== null && (bool) $pivot->is_primary;
        });

        $candidates = $primary->isNotEmpty() ? $primary : $areas;

        foreach ($hierarchy->levels as $level) {
            if ($level->kind !== 'area') {
                continue;
            }

            $role = CountryAddressProfileResolver::roleForLevel($hierarchy, $level);

            if (isset($resolved[$role])) {
                continue;
            }

            $matches = $candidates
                ->filter(fn (AddressArea $area): bool => $this->areaMatchesLevel($area, $level))
                ->values();

            if ($matches->count() === 1 && $matches->first() instanceof AddressArea) {
                $resolved[$role] = ['hierarchy' => $hierarchy, 'level' => $level, 'area' => $matches->first()];
                $resolvedRolesByHierarchy[$hierarchy->key][] = $role;
            }
        }
    }

    /**
     * Fill unresolved levels from deeper matches' ancestors.
     *
     * @param  list<AddressHierarchyDefinition>  $hierarchies
     * @param  array<string, array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}>  $resolved
     * @param  array<string, list<string>>  $resolvedRolesByHierarchy
     */
    private function recoverAncestors(array $hierarchies, array &$resolved, array &$resolvedRolesByHierarchy): void
    {
        foreach ($hierarchies as $hierarchy) {
            $areaLevels = array_values(array_filter(
                $hierarchy->levels,
                static fn (AddressLevelDefinition $level): bool => $level->kind === 'area',
            ));

            for ($index = count($areaLevels) - 1; $index >= 0; $index--) {
                $role = CountryAddressProfileResolver::roleForLevel($hierarchy, $areaLevels[$index]);

                if (isset($resolved[$role])) {
                    continue;
                }

                for ($deeper = $index + 1; $deeper < count($areaLevels); $deeper++) {
                    $deeperRole = CountryAddressProfileResolver::roleForLevel($hierarchy, $areaLevels[$deeper]);

                    if (! isset($resolved[$deeperRole])) {
                        continue;
                    }

                    $ancestor = $this->addressAreaHierarchyResolver->ancestorOfTypes(
                        $resolved[$deeperRole]['area'],
                        $this->levelTypes($areaLevels[$index]),
                        $areaLevels[$index]->hierarchyType ?? $hierarchy->key,
                    );

                    if ($ancestor instanceof AddressArea && $this->areaMatchesLevel($ancestor, $areaLevels[$index])) {
                        $resolved[$role] = ['hierarchy' => $hierarchy, 'level' => $areaLevels[$index], 'area' => $ancestor];
                        $resolvedRolesByHierarchy[$hierarchy->key][] = $role;

                        break;
                    }
                }
            }
        }
    }

    /**
     * @param  list<AddressHierarchyDefinition>  $hierarchies
     * @param  list<string>  $stateTypes
     * @param  array<string, array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}>  $resolved
     */
    private function recoverAreaTreeRoot(
        array $hierarchies,
        ?AddressLevelDefinition $stateLevel,
        array $stateTypes,
        array $resolved,
    ): ?AddressArea {
        if ($stateTypes === []) {
            return null;
        }

        $hierarchyKeys = [];

        foreach ($hierarchies as $hierarchy) {
            foreach ($hierarchy->levels as $level) {
                if ($level === $stateLevel) {
                    $hierarchyKeys[] = $level->hierarchyType ?? $hierarchy->key;
                }
            }

            $hierarchyKeys[] = $hierarchy->key;
        }

        $hierarchyKeys = array_values(array_unique($hierarchyKeys));

        if ($hierarchyKeys === []) {
            $hierarchyKeys = ['administrative'];
        }

        foreach ($this->resolvedEntriesInOrder($hierarchies, $resolved) as $entry) {
            foreach ($hierarchyKeys as $hierarchyKey) {
                $root = $this->addressAreaHierarchyResolver->ancestorOfTypes($entry['area'], $stateTypes, $hierarchyKey);

                if ($root instanceof AddressArea) {
                    return $root;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<AddressHierarchyDefinition>  $hierarchies
     * @param  array<string, array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}>  $resolved
     */
    private function recoverStateId(array $hierarchies, array $resolved): ?string
    {
        foreach ($this->resolvedEntriesInOrder($hierarchies, $resolved) as $entry) {
            $stateId = AddressAreaStateBridge::stateIdForArea($entry['area']);

            if ($stateId !== null) {
                return $stateId;
            }
        }

        return null;
    }

    /**
     * Resolved entries in hierarchy, then level, order.
     *
     * @param  list<AddressHierarchyDefinition>  $hierarchies
     * @param  array<string, array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}>  $resolved
     * @return list<array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}>
     */
    private function resolvedEntriesInOrder(array $hierarchies, array $resolved): array
    {
        $ordered = [];

        foreach ($hierarchies as $hierarchy) {
            foreach ($hierarchy->levels as $level) {
                if ($level->kind !== 'area') {
                    continue;
                }

                $role = CountryAddressProfileResolver::roleForLevel($hierarchy, $level);

                if (isset($resolved[$role])) {
                    $ordered[] = $resolved[$role];
                }
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, array{hierarchy: AddressHierarchyDefinition, level: AddressLevelDefinition, area: AddressArea}>  $resolved
     * @param  array<string, list<string>>  $resolvedRolesByHierarchy
     */
    private function deepestResolvedId(string $hierarchyKey, array $resolved, array $resolvedRolesByHierarchy): ?string
    {
        $roles = $resolvedRolesByHierarchy[$hierarchyKey] ?? [];

        if ($roles === []) {
            return null;
        }

        $last = end($roles);

        return is_string($last) && isset($resolved[$last])
            ? (string) $resolved[$last]['area']->getKey()
            : null;
    }

    /**
     * @param  list<AddressHierarchyDefinition>  $hierarchies
     * @param  array<string, string>  $assignments
     * @return array<string, string>
     */
    private function orderAssignments(array $hierarchies, array $assignments): array
    {
        $ordered = [];

        foreach ($hierarchies as $hierarchy) {
            foreach ($hierarchy->levels as $level) {
                if ($level->kind !== 'area') {
                    continue;
                }

                $role = CountryAddressProfileResolver::roleForLevel($hierarchy, $level);

                if (isset($assignments[$role])) {
                    $ordered[$role] = $assignments[$role];
                }
            }
        }

        foreach ($assignments as $role => $areaId) {
            $ordered[$role] ??= $areaId;
        }

        return $ordered;
    }

    /**
     * @param  list<AddressHierarchyDefinition>  $hierarchies
     * @return list<string>
     */
    private function stateAreaTypes(array $hierarchies, ?AddressLevelDefinition $stateLevel): array
    {
        if ($stateLevel instanceof AddressLevelDefinition) {
            if ($stateLevel->areaTypes !== []) {
                return $stateLevel->areaTypes;
            }

            if ($stateLevel->areaType !== null) {
                return [$stateLevel->areaType];
            }

            return [];
        }

        return $hierarchies === [] ? ['state', 'wilayah_persekutuan'] : [];
    }

    /** @return list<string> */
    private function levelTypes(AddressLevelDefinition $level): array
    {
        if ($level->areaTypes !== []) {
            return $level->areaTypes;
        }

        if ($level->areaType !== null) {
            return [$level->areaType];
        }

        return [];
    }

    /** @return list<int> */
    private function levelLevels(AddressLevelDefinition $level): array
    {
        if ($level->areaLevels !== []) {
            return $level->areaLevels;
        }

        if ($level->areaLevel !== null) {
            return [$level->areaLevel];
        }

        return [];
    }

    private function areaMatchesLevel(AddressArea $area, AddressLevelDefinition $level): bool
    {
        $types = $this->levelTypes($level);
        $levels = $this->levelLevels($level);

        return ($types === [] || in_array($area->type, $types, true))
            && ($levels === [] || in_array($area->level, $levels, true));
    }

    private function resolveCountryCode(?AddressCountry $country, ?string $countryId): ?string
    {
        $iso2 = $country instanceof AddressCountry ? $country->iso2 : null;

        if (is_string($iso2) && $iso2 !== '') {
            return mb_strtoupper($iso2);
        }

        if ($countryId === null) {
            return null;
        }

        $stored = AddressCountry::query()->whereKey($countryId)->value('iso2');

        return is_string($stored) && $stored !== '' ? mb_strtoupper($stored) : null;
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function preferredString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<array{longText: string|null, shortText: string|null, types: list<string>}>
     */
    private function normalizeAddressComponents(mixed $components): array
    {
        if (! is_array($components)) {
            return [];
        }

        return collect($components)
            ->map(function (mixed $component): ?array {
                if (! is_array($component)) {
                    return null;
                }

                $types = $component['types'] ?? null;

                if (! is_array($types)) {
                    return null;
                }

                return [
                    'longText' => $this->stringValue($component['longText'] ?? null),
                    'shortText' => $this->stringValue($component['shortText'] ?? null),
                    'types' => array_values(array_filter(
                        array_map(
                            fn (mixed $type): ?string => is_string($type) && $type !== '' ? $type : null,
                            $types,
                        ),
                        static fn (?string $type): bool => $type !== null,
                    )),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<array{longText: string|null, shortText: string|null, types: list<string>}>  $components
     * @param  list<string>  $types
     */
    private function componentValue(array $components, array $types): ?string
    {
        foreach ($components as $component) {
            foreach ($types as $type) {
                if (! in_array($type, $component['types'], true)) {
                    continue;
                }

                return $component['longText'] ?? $component['shortText'];
            }
        }

        return null;
    }

    /**
     * Deduplicated component values in mapping order.
     *
     * @param  list<array{longText: string|null, shortText: string|null, types: list<string>}>  $components
     * @param  list<string>  $types
     * @return list<string>
     */
    private function orderedCandidateNames(array $components, array $types): array
    {
        $names = [];

        foreach ($types as $type) {
            $value = $this->componentValue($components, [$type]);

            if (is_string($value) && $value !== '' && ! in_array($value, $names, true)) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * @param  list<array{longText: string|null, shortText: string|null, types: list<string>}>  $components
     * @param  list<string>  $types
     * @return array{longText: string|null, shortText: string|null, types: list<string>}|null
     */
    private function component(array $components, array $types): ?array
    {
        foreach ($components as $component) {
            foreach ($types as $type) {
                if (! in_array($type, $component['types'], true)) {
                    continue;
                }

                return $component;
            }
        }

        return null;
    }

    /**
     * @param  list<array{longText: string|null, shortText: string|null, types: list<string>}>  $components
     */
    private function resolveLine1(array $components): ?string
    {
        $streetNumber = $this->componentValue($components, ['street_number']);
        $route = $this->componentValue($components, ['route']);
        $premise = $this->componentValue($components, ['premise']);

        return $this->firstFilled([
            $this->joinParts([$streetNumber, $route]),
            $route,
            $premise,
        ]);
    }

    /**
     * @param  list<array{longText: string|null, shortText: string|null, types: list<string>}>  $components
     */
    private function resolveLine2(array $components): ?string
    {
        return $this->firstFilled([
            $this->componentValue($components, ['sublocality_level_1']),
            $this->componentValue($components, ['sublocality_level_2']),
            $this->componentValue($components, ['sublocality']),
            $this->componentValue($components, ['neighborhood']),
            $this->componentValue($components, ['subpremise']),
        ]);
    }

    /**
     * @param  list<array{longText: string|null, shortText: string|null, types: list<string>}>  $components
     */
    private function resolveCountry(array $components): ?AddressCountry
    {
        $countryComponent = $this->component($components, ['country']);

        if (! is_array($countryComponent)) {
            return null;
        }

        $shortCode = $this->stringValue($countryComponent['shortText'] ?? null);

        if (is_string($shortCode) && strlen($shortCode) === 2) {
            $country = AddressCountry::query()
                ->where('iso2', Str::upper($shortCode))
                ->first();

            if ($country instanceof AddressCountry) {
                return $country;
            }
        }

        $countryName = $this->firstFilled([
            $countryComponent['longText'] ?? null,
            $countryComponent['shortText'] ?? null,
        ]);

        if (! filled($countryName)) {
            return null;
        }

        /** @var Collection<int, AddressCountry> $matches */
        $matches = AddressCountry::query()
            ->get()
            ->filter(fn (AddressCountry $country): bool => $this->normalizeLocationName((string) $country->name) === $this->normalizeLocationName($countryName))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Match an area by name, then by provider-authored alias.
     *
     * Aliases only run when no primary name matches, so ambiguity semantics
     * stay exactly as before: several name matches never resolve to one.
     *
     * @param  list<string>|null  $types
     * @param  list<int>|null  $levels
     */
    private function resolveArea(
        ?string $name,
        ?string $countryId,
        ?string $parentId,
        ?array $types,
        ?array $levels = null,
        ?string $countryCode = null,
    ): ?AddressArea {
        if (! filled($name)) {
            return null;
        }

        $normalized = $this->normalizeLocationName($name, $countryCode);

        /** @var Collection<int, AddressArea> $matches */
        $matches = $this->scopedAreaQuery($countryId, $parentId, $types, $levels)
            ->get()
            ->filter(fn (AddressArea $area): bool => $this->normalizeLocationName($area->name, $countryCode) === $normalized)
            ->values();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() !== 0 || $normalized === '') {
            return null;
        }

        $areaIds = $this->scopedAreaQuery($countryId, $parentId, $types, $levels)->pluck('id')->all();

        if ($areaIds === []) {
            return null;
        }

        $matched = AddressAreaName::query()
            ->whereIn('address_area_id', $areaIds)
            ->get()
            ->filter(function (AddressAreaName $alias) use ($normalized, $countryCode): bool {
                $aliasName = $alias->getAttribute('name');

                return is_string($aliasName)
                    && $this->normalizeLocationName($aliasName, $countryCode) === $normalized;
            })
            ->map(static fn (AddressAreaName $alias): string => (string) $alias->getAttribute('address_area_id'))
            ->unique()
            ->values();

        if ($matched->count() !== 1) {
            return null;
        }

        $area = AddressArea::query()->find($matched->first());

        return $area instanceof AddressArea ? $area : null;
    }

    /**
     * @param  list<string>|null  $types
     * @param  list<int>|null  $levels
     * @return Builder<AddressArea>
     */
    private function scopedAreaQuery(?string $countryId, ?string $parentId, ?array $types, ?array $levels): Builder
    {
        $query = AddressArea::query();

        if ($countryId !== null) {
            $query->where('country_id', $countryId);
        }

        if ($parentId !== null) {
            $query->where('parent_id', $parentId);
        }

        if ($types !== null && $types !== []) {
            $query->whereIn('type', $types);
        }

        if ($levels !== null && $levels !== []) {
            $query->whereIn('level', $levels);
        }

        return $query;
    }

    private function resolveStateId(?string $stateName, ?string $countryId, ?AddressArea $areaTreeRoot, ?string $countryCode = null): ?string
    {
        if ($areaTreeRoot instanceof AddressArea) {
            $bridged = AddressAreaStateBridge::stateIdForArea($areaTreeRoot);

            if ($bridged !== null) {
                return $bridged;
            }
        }

        if (! filled($stateName) || $countryId === null) {
            return null;
        }

        /** @var Collection<int, State> $matches */
        $matches = State::query()
            ->where('country_id', $countryId)
            ->get()
            ->filter(function (State $state) use ($stateName, $countryCode): bool {
                $normalized = $this->normalizeLocationName($stateName, $countryCode);

                return $this->normalizeLocationName($state->name, $countryCode) === $normalized;
            })
            ->values();

        return $matches->count() === 1 ? (string) $matches->first()->getKey() : null;
    }

    private function resolveCityId(?string $cityName, ?string $stateId, ?string $countryId, ?string $countryCode = null): ?string
    {
        if (! filled($cityName)) {
            return null;
        }

        $query = City::query();

        if ($stateId !== null) {
            $query->where('state_id', $stateId);
        } elseif ($countryId !== null) {
            $query->where('country_id', $countryId);
        } else {
            return null;
        }

        /** @var Collection<int, City> $matches */
        $matches = $query
            ->get()
            ->filter(fn (City $city): bool => $this->normalizeLocationName($city->name, $countryCode) === $this->normalizeLocationName($cityName, $countryCode))
            ->values();

        return $matches->count() === 1 ? (string) $matches->first()->getKey() : null;
    }

    private function displayNameValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $text = $value['text'] ?? null;

            return $this->stringValue($text);
        }

        return $this->stringValue($value);
    }

    /**
     * @param  list<?string>  $values
     */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            $value = $this->stringValue($value);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<?string>  $parts
     */
    private function joinParts(array $parts): ?string
    {
        $parts = array_values(array_filter(
            array_map($this->stringValue(...), $parts),
            static fn (?string $part): bool => $part !== null,
        ));

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function numericValue(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function uuidValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return Str::isUuid($value) ? $value : null;
    }

    private function normalizeLocationName(?string $value, ?string $countryCode = null): string
    {
        if (! is_string($value)) {
            return '';
        }

        $text = Str::lower($value);

        if ($countryCode !== null) {
            $text = $this->stripCountryPrefixes($text, $countryCode);
        }

        return (string) Str::of($text)
            ->replaceMatches('/\b(?:district|daerah)\b/u', ' ')
            ->replaceMatches('/[^\pL\pN]+/u', ' ')
            ->squish();
    }

    private function stripCountryPrefixes(string $lowered, string $countryCode): string
    {
        $prefixes = GooglePlaceComponentMapper::namePrefixesToStrip($countryCode);

        if ($prefixes === []) {
            return $lowered;
        }

        $alternation = implode('|', array_map(
            static fn (string $prefix): string => preg_quote($prefix, '/'),
            $prefixes,
        ));

        $text = ltrim($lowered);

        while (preg_match("/^(?:{$alternation})\\b\\s*/u", $text, $match) === 1) {
            if (! isset($match[0]) || $match[0] === '') {
                break;
            }

            $text = substr($text, strlen($match[0]));
        }

        return $text;
    }
}
