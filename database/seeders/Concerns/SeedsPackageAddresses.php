<?php

namespace Database\Seeders\Concerns;

use AIArmada\Addressing\Actions\SyncAddressAreaAssignmentsAction;
use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaStateBridge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait SeedsPackageAddresses
{
    protected function malaysiaCountry(): ?AddressCountry
    {
        return AddressCountry::query()->where('iso2', 'MY')->first();
    }

    /**
     * Package State rows for Malaysia (addresses.state_id).
     *
     * @return Collection<int, State>
     */
    protected function malaysiaPackageStates(): Collection
    {
        $country = $this->malaysiaCountry();

        if (! $country instanceof AddressCountry) {
            return collect();
        }

        return State::query()
            ->where('country_id', $country->getKey())
            ->orderBy('name')
            ->get();
    }

    protected function malaysiaPackageStateByName(string $name): ?State
    {
        $needle = Str::lower(trim($name));

        if ($needle === '') {
            return null;
        }

        return $this->malaysiaPackageStates()
            ->first(function (State $state) use ($needle): bool {
                $candidates = [
                    Str::lower((string) $state->name),
                ];

                foreach ($candidates as $haystack) {
                    if ($haystack === '') {
                        continue;
                    }

                    if (Str::contains($haystack, $needle) || Str::contains($needle, $haystack)) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * AddressArea level-1 tree node matching a package State (district parent only).
     */
    protected function malaysiaAreaStateForPackageState(State $state): ?AddressArea
    {
        $areaId = AddressAreaStateBridge::areaIdForState($state, 'administrative');

        if ($areaId === null) {
            return null;
        }

        $area = AddressArea::query()->find($areaId);

        return $area instanceof AddressArea ? $area : null;
    }

    protected function malaysiaAreaByName(string $name, int $level, ?string $parentId = null): ?AddressArea
    {
        $needle = Str::lower(trim($name));

        if ($needle === '') {
            return null;
        }

        return AddressArea::query()
            ->where('country_code', 'MY')
            ->where('level', $level)
            ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('name')
            ->get()
            ->first(function (AddressArea $area) use ($needle): bool {
                $haystack = Str::lower($area->name);

                return Str::contains($haystack, $needle) || Str::contains($needle, $haystack);
            });
    }

    protected function randomDistrictForState(State $state): ?AddressArea
    {
        $areaState = $this->malaysiaAreaStateForPackageState($state);

        if (! $areaState instanceof AddressArea) {
            return null;
        }

        return AddressArea::query()
            ->where('parent_id', $areaState->getKey())
            ->where('level', 2)
            ->where('type', 'district')
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
            ->where('level', 3)
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
            app(SyncAddressAreaAssignmentsAction::class)->execute($address, $assignments, $address->state_id, ['source' => 'ilmu360-seeder']);

            return $address;
        }

        $address = Address::query()->create($attributes);

        if (method_exists($model, 'attachAddress')) {
            $model->attachAddress($address, 'primary', true);
        }

        app(SyncAddressAreaAssignmentsAction::class)->execute($address, $assignments, $address->state_id, ['source' => 'ilmu360-seeder']);

        return $address;
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
