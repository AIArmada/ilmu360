<?php

namespace App\Actions\Location;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaHierarchyResolver;
use AIArmada\Addressing\Support\AddressAreaStateBridge;
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
        $countryId = ($country instanceof AddressCountry ? (string) $country->id : null)
            ?? $this->uuidValue($payload['fallbackCountryId'] ?? null);

        $stateName = $this->componentValue($components, ['administrative_area_level_1']);
        $districtName = $this->componentValue($components, ['administrative_area_level_2']);
        $cityName = $this->firstFilled([
            $this->componentValue($components, ['locality']),
            $this->componentValue($components, ['postal_town']),
        ]);
        // Locality/postal-town names are the most useful fallback for the deepest configured area.
        $subdistrictName = $this->firstFilled([
            $this->componentValue($components, ['locality']),
            $this->componentValue($components, ['postal_town']),
            $this->componentValue($components, ['administrative_area_level_3']),
        ]);
        $postalLocalityName = $this->firstFilled([
            $this->componentValue($components, ['locality']),
            $this->componentValue($components, ['postal_town']),
            $this->componentValue($components, ['sublocality_level_1']),
            $this->componentValue($components, ['sublocality_level_2']),
            $this->componentValue($components, ['sublocality']),
            $this->componentValue($components, ['neighborhood']),
        ]);

        $areaTreeRoot = $this->resolveArea($stateName, $countryId, null, ['state', 'wilayah_persekutuan']);
        $countryId = $areaTreeRoot->country_id ?? $countryId;
        $stateId = $this->resolveStateId($stateName, $countryId, $areaTreeRoot);
        $areaTreeRootId = AddressAreaStateBridge::areaIdForState($stateId, 'administrative');
        $areaTreeRoot = $areaTreeRootId !== null
            ? AddressArea::query()->find($areaTreeRootId)
            : $areaTreeRoot;
        $district = $this->resolveArea($districtName, $countryId, $areaTreeRoot?->id, ['district', 'minor_district']);
        $subdistrict = $this->resolveArea($subdistrictName, $countryId, $district->id ?? $areaTreeRoot?->id, ['mukim', 'subdistrict']);
        $postalLocality = null;

        // Google may return a subdivision without the administrative district.
        // Resolve that incomplete provider response within the state's administrative
        // hierarchy, then recover the district from the matched area's ancestors.
        if ($subdistrict === null && $district === null) {
            $subdistrict = $this->addressAreaHierarchyResolver->resolveWithinHierarchy(
                $subdistrictName,
                $countryId,
                $areaTreeRoot?->id,
                'administrative',
                ['city', 'municipality', 'mukim', 'subdistrict'],
            );

            if ($subdistrict === null && $areaTreeRoot?->type === 'wilayah_persekutuan') {
                $postalLocality = $this->addressAreaHierarchyResolver->resolveRoleWithinHierarchy(
                    $postalLocalityName,
                    $countryId,
                    $areaTreeRoot->id,
                    'postal',
                    'postal_locality',
                );
            }
        }

        $district ??= $this->addressAreaHierarchyResolver->ancestorOfTypes(
            $subdistrict,
            ['district', 'minor_district'],
            'administrative',
        );
        $areaTreeRoot ??= $this->addressAreaHierarchyResolver->ancestorOfTypes(
            $district,
            ['state', 'wilayah_persekutuan'],
            'administrative',
        );
        $countryId = $areaTreeRoot->country_id ?? $district->country_id ?? $subdistrict->country_id ?? $countryId;

        $stateId ??= $this->resolveStateId($stateName, $countryId, $areaTreeRoot);
        $cityId = $this->resolveCityId($cityName, $stateId, $countryId);

        $districtId = $district instanceof AddressArea && in_array($district->type, ['district', 'minor_district'], true)
            ? $district->id
            : null;
        $subdistrictId = $subdistrict instanceof AddressArea && in_array($subdistrict->type, ['mukim', 'subdistrict'], true)
            ? $subdistrict->id
            : null;
        $postalLocalityId = $postalLocality?->id;

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
            'area_assignments' => array_filter([
                'administrative_district' => $districtId,
                'administrative_subdivision' => $subdistrictId,
                'postal_locality' => $postalLocalityId,
            ]),
            'line1' => $this->resolveLine1($components),
            'line2' => $this->resolveLine2($components),
            'postcode' => $this->componentValue($components, ['postal_code']),
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
     * @param  list<string>|null  $types
     */
    private function resolveArea(?string $name, ?string $countryId, ?string $parentId, ?array $types): ?AddressArea
    {
        if (! filled($name)) {
            return null;
        }

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

        /** @var Collection<int, AddressArea> $matches */
        $matches = $query
            ->get()
            ->filter(fn (AddressArea $area): bool => $this->normalizeLocationName($area->name) === $this->normalizeLocationName($name))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveStateId(?string $stateName, ?string $countryId, ?AddressArea $areaTreeRoot): ?string
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
            ->filter(function (State $state) use ($stateName): bool {
                $normalized = $this->normalizeLocationName($stateName);

                return $this->normalizeLocationName($state->name) === $normalized;
            })
            ->values();

        return $matches->count() === 1 ? (string) $matches->first()->getKey() : null;
    }

    private function resolveCityId(?string $cityName, ?string $stateId, ?string $countryId): ?string
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
            ->filter(fn (City $city): bool => $this->normalizeLocationName($city->name) === $this->normalizeLocationName($cityName))
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

    private function normalizeLocationName(?string $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return (string) Str::of(Str::lower($value))
            ->replaceMatches('/\b(?:district|daerah)\b/u', ' ')
            ->replaceMatches('/[^\pL\pN]+/u', ' ')
            ->squish();
    }
}
