<?php

namespace App\Actions\Location;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveGooglePlaceSelectionAction
{
    use AsAction;

    public function __construct(
        private readonly NormalizeGoogleMapsInputAction $normalizeGoogleMapsInputAction,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     country_id: string|null,
     *     admin_area_1_id: string|null,
     *     admin_area_2_id: string|null,
     *     admin_area_3_id: string|null,
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
        $subdistrictName = $this->firstFilled([
            $this->componentValue($components, ['locality']),
            $this->componentValue($components, ['postal_town']),
            $this->componentValue($components, ['administrative_area_level_3']),
        ]);

        $state = $this->resolveArea($stateName, $countryId, null, 1);
        $district = $this->resolveArea($districtName, $countryId, $state?->id, 2);
        $subdistrict = $this->resolveArea($subdistrictName, $countryId, $district->id ?? $state?->id, 3);

        $district ??= $subdistrict?->parent_id !== null
            ? AddressArea::query()->find($subdistrict->parent_id)
            : null;
        $state ??= $district?->parent_id !== null
            ? AddressArea::query()->find($district->parent_id)
            : null;
        $countryId = $state->country_id ?? $district->country_id ?? $subdistrict->country_id ?? $countryId;

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
            'admin_area_1_id' => $state?->id,
            'admin_area_2_id' => $district?->id,
            'admin_area_3_id' => $subdistrict?->id,
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

    private function resolveArea(?string $name, ?string $countryId, ?string $parentId, ?int $level): ?AddressArea
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

        if ($level !== null) {
            $query->where('level', $level);
        }

        /** @var Collection<int, AddressArea> $matches */
        $matches = $query
            ->get()
            ->filter(fn (AddressArea $area): bool => $this->normalizeLocationName($area->name) === $this->normalizeLocationName($name))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
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
