<?php

declare(strict_types=1);

namespace App\Data\Institutions;

use AIArmada\Addressing\Data\AddressData;
use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressingTableResolver;
use AIArmada\Addressing\Support\CountryAddressProfileResolver;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Data;

class InstitutionAddressData extends Data
{
    public function __construct(
        public string $country_id,
        public ?string $state_id = null,
        public ?string $city_id = null,
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $line3 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postcode = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        /** @var array<string, string>|null role => address area UUID */
        public ?array $area_assignments = null,
        /** @var array<string, mixed>|null package Address metadata (e.g. feed_geography source labels) */
        public ?array $metadata = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $areasTable = AddressingTableResolver::resolve('areas');

        return [
            'country_id' => ['required', 'uuid', Rule::exists(AddressingTableResolver::resolve('countries'), 'id')],
            'state_id' => ['nullable', 'uuid', Rule::exists(AddressingTableResolver::resolve('states'), 'id')],
            'city_id' => ['nullable', 'uuid', Rule::exists(AddressingTableResolver::resolve('cities'), 'id')],
            'line1' => ['nullable', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'line3' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'area_assignments' => ['nullable', 'array'],
            'area_assignments.*' => ['uuid', Rule::exists($areasTable, 'id')],
            'metadata' => ['nullable', 'array'],
            'metadata.feed_geography' => ['nullable', 'array'],
            'metadata.feed_geography.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Runs when this Data is the validation root; the full-graph boundary
     * instead delegates here per address payload from its own hook.
     */
    #[\Override]
    public static function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();

            if (is_array($data)) {
                self::assertCoherentPayload($data, $validator, '');
            }
        });
    }

    /**
     * Reject incoherent geography and roles the country's address profile
     * does not support, before any write.
     *
     * Existence alone would accept a state or city from another country and
     * an unknown role; the selected country profile is the vocabulary, so no
     * Malaysia-specific hierarchy is enforced here. State is nullable because
     * profiles differ by country (some define no state level), so coherence
     * is checked for the ids actually supplied: a supplied state must belong
     * to the country, a supplied city must always belong to the country, and
     * when a state is also supplied the city's canonical state must match it,
     * mirroring the package normalizer.
     *
     * @param  array<string, mixed>  $item
     */
    public static function assertCoherentPayload(array $item, Validator $validator, string $prefix): void
    {
        $countryId = $item['country_id'] ?? null;
        $stateId = $item['state_id'] ?? null;
        $cityId = $item['city_id'] ?? null;

        if (is_string($countryId) && $countryId !== '' && is_string($stateId) && $stateId !== '') {
            $state = State::query()->whereKey($stateId)->first(['id', 'country_id']);

            if ($state instanceof State && (string) $state->country_id !== $countryId) {
                $validator->errors()->add($prefix.'state_id', 'The selected state does not belong to the selected country.');
            }
        }

        if (is_string($countryId) && $countryId !== '' && is_string($cityId) && $cityId !== '') {
            $city = City::query()->whereKey($cityId)->first(['id', 'country_id', 'state_id']);

            if ($city instanceof City) {
                if ((string) $city->country_id !== $countryId) {
                    $validator->errors()->add($prefix.'city_id', 'The selected city does not belong to the selected country.');
                } elseif (is_string($stateId) && $stateId !== '' && (string) $city->state_id !== $stateId) {
                    $validator->errors()->add($prefix.'city_id', 'The selected city does not belong to the selected state.');
                }
            }
        }

        $assignments = $item['area_assignments'] ?? null;

        if (is_array($assignments) && $assignments !== [] && is_string($countryId) && $countryId !== '') {
            $supported = app(CountryAddressProfileResolver::class)->assignmentRoles($countryId);

            foreach ($assignments as $role => $areaId) {
                if (! is_string($role) || ! in_array($role, $supported, true)) {
                    $validator->errors()->add($prefix.'area_assignments.'.$role, 'The address area role is not supported by the country address profile.');
                }
            }
        }
    }

    public function toAddressData(): AddressData
    {
        return AddressData::from([
            'line1' => $this->line1,
            'line2' => $this->line2,
            'line3' => $this->line3,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'countryId' => $this->country_id,
            'stateId' => $this->state_id,
            'cityId' => $this->city_id,
            'metadata' => $this->metadata ?? [],
        ]);
    }

    public function toLocationData(): AddressLocationData
    {
        return AddressLocationData::fromArray([
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'area_assignments' => $this->area_assignments ?? [],
        ]);
    }
}
