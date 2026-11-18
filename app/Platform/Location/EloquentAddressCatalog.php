<?php

declare(strict_types=1);

namespace App\Platform\Location;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use App\Platform\Location\Api\AddressCatalog;
use App\Platform\Ui\Forms\SharedFormSchema;
use Illuminate\Support\Str;

final readonly class EloquentAddressCatalog implements AddressCatalog
{
    public function countries(): array
    {
        return AddressCountry::query()
            ->orderBy('name')
            ->get(['id', 'name', 'iso2'])
            ->map(fn (AddressCountry $country): array => [
                'id' => (string) $country->id,
                'label' => (string) $country->name,
                'iso2' => strtoupper((string) $country->iso2),
                'key' => Str::slug((string) $country->name),
            ])
            ->all();
    }

    public function states(?string $countryId): array
    {
        if (! is_string($countryId) || $countryId === '') {
            return [];
        }

        if (app(LocationSlugResolver::class)->stateMaps($countryId)['options'] === []) {
            return [];
        }

        return State::query()
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (State $state): array => [
                'id' => (string) $state->id,
                'label' => (string) $state->name,
                'code' => $state->code !== null ? (string) $state->code : null,
            ])
            ->all();
    }

    public function cities(?string $stateId, ?string $countryId = null): array
    {
        $query = City::query()->orderBy('name');

        if (is_string($stateId) && $stateId !== '') {
            $query->where('state_id', $stateId);
        } elseif (is_string($countryId) && $countryId !== '') {
            $query->where('country_id', $countryId);
        } else {
            return [];
        }

        return $query
            ->get(['id', 'name'])
            ->map(fn (City $city): array => [
                'id' => (string) $city->id,
                'label' => (string) $city->name,
            ])
            ->all();
    }

    public function administrativeDistricts(?string $countryId, ?string $stateId = null): array
    {
        if (($countryId === null || $countryId === '') && is_string($stateId) && $stateId !== '') {
            $countryId = $this->countryIdForState($stateId);
        }

        if (! is_string($countryId) || $countryId === '') {
            return [];
        }

        $role = app(LocationSlugResolver::class)->districtRoleForCountry($countryId);

        if ($role === null) {
            return [];
        }

        return $this->roleAreaOptions($countryId, $role, $stateId);
    }

    public function administrativeSubdivisions(?string $districtId, ?string $countryId = null, ?string $stateId = null): array
    {
        $hasDistrict = is_string($districtId) && $districtId !== '';
        $hasState = is_string($stateId) && $stateId !== '';

        if ($countryId === null || $countryId === '') {
            $countryId = $hasDistrict
                ? $this->countryIdForArea($districtId)
                : ($hasState ? $this->countryIdForState($stateId) : null);
        }

        if (! is_string($countryId) || $countryId === '') {
            return [];
        }

        $role = app(LocationSlugResolver::class)->subdivisionRoleForCountry($countryId);

        if ($role === null) {
            return [];
        }

        if ($hasDistrict) {
            return $this->roleAreaOptions($countryId, $role, $districtId);
        }

        if ($hasState) {
            return $this->roleAreaOptions($countryId, $role, $stateId);
        }

        return $this->roleAreaOptions($countryId, $role);
    }

    public function addressAreas(?string $countryId = null, ?string $parentId = null, ?int $level = null): array
    {
        $query = AddressArea::query();

        if (is_string($parentId) && $parentId !== '') {
            $query->where('parent_id', $parentId);
        }

        if (is_string($countryId) && $countryId !== '') {
            $query->where('country_id', $countryId);
        } elseif (! is_string($parentId) || $parentId === '') {
            return [];
        }

        if ($level !== null) {
            $query->where('level', $level);
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'level'])
            ->map(fn (AddressArea $area): array => [
                'id' => (string) $area->id,
                'label' => (string) $area->name,
                'type' => (string) $area->type,
                'level' => $area->level,
            ])
            ->all();
    }

    /** @return list<array{id: string, label: string, type: string, level: int|null}> */
    private function roleAreaOptions(?string $countryId, string $role, ?string $parentId = null): array
    {
        $options = SharedFormSchema::areaOptionsForRole($countryId, $role, $parentId);

        if ($options === []) {
            return [];
        }

        return AddressArea::query()
            ->whereIn('id', array_keys($options))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'level'])
            ->map(fn (AddressArea $area): array => [
                'id' => (string) $area->id,
                'label' => (string) $area->name,
                'type' => (string) $area->type,
                'level' => $area->level !== null ? (int) $area->level : null,
            ])
            ->all();
    }

    private function countryIdForState(string $stateId): ?string
    {
        $countryId = State::query()->whereKey($stateId)->value('country_id');

        return is_string($countryId) ? $countryId : null;
    }

    private function countryIdForArea(string $areaId): ?string
    {
        $countryId = AddressArea::query()->whereKey($areaId)->value('country_id');

        if (is_string($countryId) && $countryId !== '') {
            return $countryId;
        }

        return $this->countryIdForState($areaId);
    }
}
