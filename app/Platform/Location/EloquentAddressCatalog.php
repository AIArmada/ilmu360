<?php

declare(strict_types=1);

namespace App\Platform\Location;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use App\Platform\Location\Api\AddressCatalog;
use App\Platform\Ui\Forms\AddressFields;
use App\Shared\Cache\SelectionCatalog;
use Illuminate\Support\Str;

final readonly class EloquentAddressCatalog implements AddressCatalog
{
    public function __construct(
        private SelectionCatalog $selectionCatalog,
    ) {}

    public function countries(): array
    {
        return collect($this->selectionCatalog->countryRows())
            ->map(static fn (array $country): array => [
                'id' => $country['id'],
                'label' => $country['name'],
                'iso2' => strtoupper($country['iso2']),
                'key' => Str::slug($country['name']),
            ])
            ->values()
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

        /** @var list<array{id: string, label: string, code: string|null}> $rows */
        $rows = $this->selectionCatalog->rememberAddressData(
            "catalog:states:{$countryId}",
            static fn (): array => State::query()
                ->where('country_id', $countryId)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(static fn (State $state): array => [
                    'id' => (string) $state->id,
                    'label' => (string) $state->name,
                    'code' => $state->code !== null ? (string) $state->code : null,
                ])
                ->all(),
        );

        return $rows;
    }

    public function cities(?string $stateId, ?string $countryId = null): array
    {
        $hasState = is_string($stateId) && $stateId !== '';
        $hasCountry = is_string($countryId) && $countryId !== '';

        if (! $hasState && ! $hasCountry) {
            return [];
        }

        $cacheKey = ($hasState ? $stateId : 'any').':'.($hasCountry ? $countryId : 'any');

        /** @var list<array{id: string, label: string}> $rows */
        $rows = $this->selectionCatalog->rememberAddressData(
            "catalog:cities:{$cacheKey}",
            static function () use ($hasState, $stateId, $hasCountry, $countryId): array {
                $query = City::query()->orderBy('name');

                if ($hasState) {
                    $query->where('state_id', $stateId);
                } elseif ($hasCountry) {
                    $query->where('country_id', $countryId);
                }

                return $query
                    ->get(['id', 'name'])
                    ->map(static fn (City $city): array => [
                        'id' => (string) $city->id,
                        'label' => (string) $city->name,
                    ])
                    ->all();
            },
        );

        return $rows;
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
        $hasParent = is_string($parentId) && $parentId !== '';
        $hasCountry = is_string($countryId) && $countryId !== '';

        if (! $hasParent && ! $hasCountry) {
            return [];
        }

        $cacheKey = ($hasCountry ? $countryId : 'any')
            .':'.($hasParent ? $parentId : 'any')
            .':'.($level !== null ? (string) $level : 'any');

        /** @var list<array{id: string, label: string, type: string, level: int|null}> $rows */
        $rows = $this->selectionCatalog->rememberAddressData(
            "catalog:areas:{$cacheKey}",
            static function () use ($hasParent, $parentId, $hasCountry, $countryId, $level): array {
                $query = AddressArea::query();

                if ($hasParent) {
                    $query->where('parent_id', $parentId);
                }

                if ($hasCountry) {
                    $query->where('country_id', $countryId);
                }

                if ($level !== null) {
                    $query->where('level', $level);
                }

                return $query
                    ->orderBy('name')
                    ->get(['id', 'name', 'type', 'level'])
                    ->map(static fn (AddressArea $area): array => [
                        'id' => (string) $area->id,
                        'label' => (string) $area->name,
                        'type' => (string) $area->type,
                        'level' => $area->level,
                    ])
                    ->all();
            },
        );

        return $rows;
    }

    /** @return list<array{id: string, label: string, type: string, level: int|null}> */
    private function roleAreaOptions(?string $countryId, string $role, ?string $parentId = null): array
    {
        $options = AddressFields::areaOptionsForRole($countryId, $role, $parentId);

        if ($options === []) {
            return [];
        }

        $cacheKey = ($countryId ?? 'any').':'.$role.':'.($parentId ?? 'root');

        /** @var list<array{id: string, label: string, type: string, level: int|null}> $rows */
        $rows = $this->selectionCatalog->rememberAddressData(
            "catalog:role-areas:{$cacheKey}",
            static fn (): array => AddressArea::query()
                ->whereIn('id', array_keys($options))
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'level'])
                ->map(static fn (AddressArea $area): array => [
                    'id' => (string) $area->id,
                    'label' => (string) $area->name,
                    'type' => (string) $area->type,
                    'level' => $area->level !== null ? (int) $area->level : null,
                ])
                ->all(),
        );

        return $rows;
    }

    private function countryIdForState(string $stateId): ?string
    {
        return $this->selectionCatalog->rememberAddressValue(
            "country-for-state:{$stateId}",
            static function () use ($stateId): ?string {
                $countryId = State::query()->whereKey($stateId)->value('country_id');

                return is_string($countryId) && $countryId !== '' ? $countryId : null;
            },
        );
    }

    private function countryIdForArea(string $areaId): ?string
    {
        $countryId = $this->selectionCatalog->rememberAddressValue(
            "country-for-parent:{$areaId}",
            static function () use ($areaId): ?string {
                $resolved = AddressArea::query()->whereKey($areaId)->value('country_id');

                return is_string($resolved) && $resolved !== '' ? $resolved : null;
            },
        );

        if (is_string($countryId) && $countryId !== '') {
            return $countryId;
        }

        return $this->countryIdForState($areaId);
    }
}
