<?php

namespace Database\Seeders\Concerns;

use AIArmada\Addressing\Actions\SyncAddressAreaAssignmentsAction;
use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaStateBridge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait SeedsPackageAddresses
{
    // ── Generic (country-agnostic) helpers ───────────────────────────

    protected function countryByIso(string $iso2): ?AddressCountry
    {
        return AddressCountry::query()->where('iso2', strtoupper($iso2))->first();
    }

    /**
     * Package State rows for a country.
     *
     * @return Collection<int, State>
     */
    protected function packageStatesByCountryId(string $countryId): Collection
    {
        return State::query()
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->get();
    }

    protected function packageStateByName(string $countryId, string $name): ?State
    {
        $needle = Str::lower(trim($name));

        if ($needle === '') {
            return null;
        }

        return $this->packageStatesByCountryId($countryId)
            ->first(function (State $state) use ($needle): bool {
                $haystack = Str::lower((string) $state->name);

                if ($haystack === '') {
                    return false;
                }

                return Str::contains($haystack, $needle) || Str::contains($needle, $haystack);
            });
    }

    /**
     * AddressArea level-1 tree node matching a package State.
     */
    protected function areaStateForPackageState(State $state): ?AddressArea
    {
        $areaId = AddressAreaStateBridge::areaIdForState($state, 'administrative');

        if ($areaId === null) {
            return null;
        }

        $area = AddressArea::query()->find($areaId);

        return $area instanceof AddressArea ? $area : null;
    }

    protected function areaByName(string $countryCode, string $name, int $level, ?string $parentId = null): ?AddressArea
    {
        $needle = Str::lower(trim($name));

        if ($needle === '') {
            return null;
        }

        return AddressArea::query()
            ->where('country_code', strtoupper($countryCode))
            ->where('level', $level)
            ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('name')
            ->get()
            ->first(function (AddressArea $area) use ($needle): bool {
                $haystack = Str::lower($area->name);

                return Str::contains($haystack, $needle) || Str::contains($needle, $haystack);
            });
    }

    // ── Malaysia convenience wrappers ────────────────────────────────

    protected function malaysiaCountry(): ?AddressCountry
    {
        return $this->countryByIso('MY');
    }

    /**
     * @return Collection<int, State>
     */
    protected function malaysiaPackageStates(): Collection
    {
        $country = $this->malaysiaCountry();

        if (! $country instanceof AddressCountry) {
            return collect();
        }

        return $this->packageStatesByCountryId((string) $country->getKey());
    }

    protected function malaysiaPackageStateByName(string $name): ?State
    {
        $country = $this->malaysiaCountry();

        if (! $country instanceof AddressCountry) {
            return null;
        }

        return $this->packageStateByName((string) $country->getKey(), $name);
    }

    protected function malaysiaAreaStateForPackageState(State $state): ?AddressArea
    {
        return $this->areaStateForPackageState($state);
    }

    protected function malaysiaAreaByName(string $name, int $level, ?string $parentId = null): ?AddressArea
    {
        return $this->areaByName('MY', $name, $level, $parentId);
    }

    protected function randomDistrictForState(State $state): ?AddressArea
    {
        $areaState = $this->malaysiaAreaStateForPackageState($state);

        if (! $areaState instanceof AddressArea) {
            return null;
        }

        return AddressArea::query()
            ->where('parent_id', $areaState->getKey())
            ->whereIn('type', ['district', 'minor_district'])
            ->inRandomOrder()
            ->first();
    }

    protected function randomSubdistrictForDistrict(?AddressArea $district): ?AddressArea
    {
        if (! $district instanceof AddressArea) {
            return null;
        }

        return AddressArea::query()
            ->where('parent_id', $district->getKey())
            ->whereIn('type', ['mukim', 'subdistrict'])
            ->inRandomOrder()
            ->first();
    }

    /**
     * Build package-native address attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function packageAddressAttributes(
        array $attributes = [],
        ?State $state = null,
        ?AddressArea $district = null,
        ?AddressArea $subdistrict = null,
    ): array {
        if ($state instanceof State) {
            $attributes['state_id'] = (string) $state->getKey();
            $attributes['state'] ??= $state->name;
            $attributes['country_id'] ??= $state->country_id;
        }

        if ($district instanceof AddressArea) {
            $attributes['area_assignments']['administrative_district'] = (string) $district->getKey();
        }

        if ($subdistrict instanceof AddressArea) {
            $attributes['area_assignments']['administrative_subdivision'] = (string) $subdistrict->getKey();
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function seedPrimaryPackageAddress(Model $model, array $attributes): Address
    {
        $attributes = $this->normalizePackageAddressAttributes($attributes);
        $assignments = (array) ($attributes['area_assignments'] ?? []);
        unset($attributes['area_assignments']);
        $address = method_exists($model, 'primaryAddress') ? $model->primaryAddress() : null;

        if ($address instanceof Address) {
            $address->fill($attributes);
            $address->save();
            $this->syncAreaAssignments($address, $assignments, $address->state_id);

            return $address;
        }

        $address = Address::query()->create($attributes);

        if (method_exists($model, 'attachAddress')) {
            $model->attachAddress($address, 'primary', true);
        }

        $this->syncAreaAssignments($address, $assignments, $address->state_id);

        return $address;
    }

    /**
     * @param  array<string, string|null>  $assignments
     */
    private function syncAreaAssignments(Address $address, array $assignments, ?string $stateId): void
    {
        if ($assignments === []) {
            return;
        }

        try {
            app(SyncAddressAreaAssignmentsAction::class)->execute($address, $assignments, $stateId, ['source' => 'ilmu360-seeder']);
        } catch (ValidationException) {
            foreach ($assignments as $role => $areaId) {
                if (! is_string($areaId) || trim($areaId) === '') {
                    continue;
                }

                AddressAreaAssignment::query()->updateOrCreate(
                    [
                        'address_id' => $address->getKey(),
                        'role' => $role,
                    ],
                    [
                        'address_area_id' => $areaId,
                        'is_primary' => true,
                        'metadata' => null,
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizePackageAddressAttributes(array $attributes): array
    {
        $country = isset($attributes['country_id']) && is_string($attributes['country_id'])
            ? AddressCountry::query()->find($attributes['country_id'])
            : null;

        $state = isset($attributes['state_id']) && is_string($attributes['state_id'])
            ? State::query()->find($attributes['state_id'])
            : null;

        $assignments = (array) ($attributes['area_assignments'] ?? []);
        $districtId = $assignments['administrative_district'] ?? null;
        $subdistrictId = $assignments['administrative_subdivision'] ?? null;
        $adminArea1 = is_string($districtId)
            ? AddressArea::query()->find($districtId)
            : null;

        $adminArea2 = is_string($subdistrictId)
            ? AddressArea::query()->find($subdistrictId)
            : null;

        // Recover package state_id when only district/subdistrict were provided.
        if (! $state instanceof State && $adminArea1 instanceof AddressArea) {
            $stateId = AddressAreaStateBridge::stateIdForArea($adminArea1);
            if ($stateId !== null) {
                $state = State::query()->find($stateId);
                $attributes['state_id'] = $stateId;
            }
        }

        if (! $state instanceof State && $adminArea2 instanceof AddressArea) {
            $stateId = AddressAreaStateBridge::stateIdForArea($adminArea2);
            if ($stateId !== null) {
                $state = State::query()->find($stateId);
                $attributes['state_id'] = $stateId;
            }
        }

        $attributes['country'] ??= $country->name ?? ($state instanceof State
            ? AddressCountry::query()->whereKey($state->country_id)->value('name')
            : null);
        $attributes['country_code'] ??= $country?->iso2;
        $attributes['state'] ??= $state?->name;

        if (! filled($attributes['state'] ?? null) && $adminArea1 instanceof AddressArea && is_string($adminArea1->parent_id)) {
            $attributes['state'] = AddressArea::query()->whereKey($adminArea1->parent_id)->value('name');
        }

        $attributes['city'] ??= is_object($adminArea2)
            ? $adminArea2->name
            : (is_object($adminArea1) ? $adminArea1->name : ($attributes['state'] ?? $attributes['country'] ?? ''));

        $attributes['formatted_address'] ??= collect([
            $attributes['line1'] ?? null,
            $attributes['line2'] ?? null,
            $attributes['postcode'] ?? null,
            $attributes['city'] ?? null,
            $attributes['state'] ?? null,
            $attributes['country'] ?? null,
        ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->implode(', ');

        return $attributes;
    }
}
