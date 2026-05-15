<?php

namespace App\Actions\Location;

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Subdistrict;
use App\Support\Location\FederalTerritoryLocation;
use App\Support\Location\PreferredCountryResolver;
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
     *     country_id: int,
     *     state_id: int|null,
     *     district_id: int|null,
     *     subdistrict_id: int|null,
     *     line1: string|null,
     *     line2: string|null,
     *     postcode: string|null,
     *     google_maps_url: string|null,
     *     google_place_id: string|null,
     *     google_display_name: string|null,
     *     lat: float|null,
     *     lng: float|null,
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
        $countryId = ($country instanceof Country ? (int) $country->id : null)
            ?? $this->locationIdValue($payload['fallbackCountryId'] ?? null)
            ?? PreferredCountryResolver::MALAYSIA_ID;

        $stateName = $this->componentValue($components, ['administrative_area_level_1']);
        $districtName = $this->componentValue($components, ['administrative_area_level_2']);
        $subdistrictName = $this->firstFilled([
            $this->componentValue($components, ['locality']),
            $this->componentValue($components, ['postal_town']),
            $this->componentValue($components, ['administrative_area_level_3']),
        ]);

        $state = $this->resolveState($stateName, $countryId);
        $district = $this->resolveDistrict($districtName, $state?->id, $countryId);
        $subdistrict = $this->resolveSubdistrict($subdistrictName, $district?->id, $state?->id, $countryId);

        if ($subdistrict instanceof Subdistrict) {
            if ($subdistrict->district_id !== null) {
                $district ??= District::query()->find($subdistrict->district_id);
            }
            $state ??= State::query()->find($subdistrict->state_id);
            $countryId = $this->locationIdValue($subdistrict->country_id) ?? $countryId;
        }

        if ($district instanceof District) {
            $state ??= State::query()->find($district->state_id);
            $countryId = $this->locationIdValue($district->country_id) ?? $countryId;
        }

        if ($state instanceof State) {
            $countryId = $this->locationIdValue($state->country_id) ?? $countryId;
        }

        if (
            $subdistrict instanceof Subdistrict
            && $district instanceof District
            && (int) $subdistrict->district_id !== (int) $district->id
        ) {
            $subdistrict = null;
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
            'state_id' => $state?->id,
            'district_id' => $district?->id,
            'subdistrict_id' => $subdistrict?->id,
            'line1' => $this->resolveLine1($components),
            'line2' => $this->resolveLine2($components),
            'postcode' => $this->componentValue($components, ['postal_code']),
            ...$googleMapsState,
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
    private function resolveCountry(array $components): ?Country
    {
        $countryComponent = $this->component($components, ['country']);

        if (! is_array($countryComponent)) {
            return null;
        }

        $shortCode = $this->stringValue($countryComponent['shortText'] ?? null);

        if (is_string($shortCode) && strlen($shortCode) === 2) {
            $country = Country::query()
                ->where('iso2', Str::upper($shortCode))
                ->first();

            if ($country instanceof Country) {
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

        /** @var Collection<int, Country> $matches */
        $matches = Country::query()
            ->get()
            ->filter(fn (Country $country): bool => $this->normalizeLocationName((string) $country->name) === $this->normalizeLocationName($countryName))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveState(?string $name, ?int $countryId = null): ?State
    {
        if (! filled($name)) {
            return null;
        }

        /** @var Collection<int, State> $matches */
        $matches = State::query()
            ->where('country_id', $countryId ?? PreferredCountryResolver::MALAYSIA_ID)
            ->get()
            ->filter(fn (State $state): bool => $this->normalizeLocationName($state->name) === $this->normalizeLocationName($name))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveDistrict(?string $name, ?int $stateId = null, ?int $countryId = null): ?District
    {
        if (! filled($name)) {
            return null;
        }

        if (FederalTerritoryLocation::isFederalTerritoryStateId($stateId)) {
            return null;
        }

        $query = District::query()->where('country_id', $countryId ?? PreferredCountryResolver::MALAYSIA_ID);

        if ($stateId !== null) {
            $query->where('state_id', $stateId);
        }

        /** @var Collection<int, District> $matches */
        $matches = $query
            ->get()
            ->filter(fn (District $district): bool => $this->normalizeLocationName($district->name) === $this->normalizeLocationName($name))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveSubdistrict(?string $name, ?int $districtId = null, ?int $stateId = null, ?int $countryId = null): ?Subdistrict
    {
        if (! filled($name)) {
            return null;
        }

        $query = Subdistrict::query()->where('country_id', $countryId ?? PreferredCountryResolver::MALAYSIA_ID);

        if ($districtId !== null) {
            $query->where('district_id', $districtId);
        } elseif ($stateId !== null) {
            $query->where('state_id', $stateId);

            if (FederalTerritoryLocation::isFederalTerritoryStateId($stateId)) {
                $query->whereNull('district_id');
            }
        }

        /** @var Collection<int, Subdistrict> $matches */
        $matches = $query
            ->get()
            ->filter(fn (Subdistrict $subdistrict): bool => $this->normalizeLocationName($subdistrict->name) === $this->normalizeLocationName($name))
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

    private function locationIdValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit(trim($value))) {
            return null;
        }

        $locationId = (int) trim($value);

        return $locationId > 0 ? $locationId : null;
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
