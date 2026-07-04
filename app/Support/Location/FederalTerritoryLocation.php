<?php

namespace App\Support\Location;

use AIArmada\Addressing\Models\AddressArea;

class FederalTerritoryLocation
{
    /**
     * @var list<string>
     */
    private const array STATE_NAME_KEYS = [
        'kuala lumpur',
        'putrajaya',
        'labuan',
        'wilayah persekutuan kuala lumpur',
        'wilayah persekutuan putrajaya',
        'wilayah persekutuan labuan',
    ];

    /**
     * @var array<string, bool>|null
     */
    private static ?array $stateIds = null;

    public static function isFederalTerritoryStateId(int|string|null $stateId): bool
    {
        $stateId = trim((string) $stateId);

        if ($stateId === '') {
            return false;
        }

        if (array_key_exists($stateId, self::stateIds())) {
            return self::$stateIds[$stateId] ?? false;
        }

        $state = AddressArea::query()
            ->whereKey($stateId)
            ->where('level', 1)
            ->first();

        if (! $state instanceof AddressArea) {
            return false;
        }

        $isFederalTerritory = strtoupper((string) $state->country_code) === 'MY'
            && self::isFederalTerritoryStateName($state->name);

        self::$stateIds[$stateId] = $isFederalTerritory;

        return $isFederalTerritory;
    }

    public static function isFederalTerritoryStateName(?string $name): bool
    {
        if (! is_string($name)) {
            return false;
        }

        return in_array(mb_strtolower(trim($name)), self::STATE_NAME_KEYS, true);
    }

    /**
     * @return array<string, bool>
     */
    public static function stateIds(): array
    {
        if (self::$stateIds !== null) {
            return self::$stateIds;
        }

        self::$stateIds = AddressArea::query()
            ->where('country_code', 'MY')
            ->where('level', 1)
            ->whereIn('name', [
                'Kuala Lumpur',
                'Putrajaya',
                'Labuan',
                'Wilayah Persekutuan Kuala Lumpur',
                'Wilayah Persekutuan Putrajaya',
                'Wilayah Persekutuan Labuan',
            ])
            ->pluck('id')
            ->mapWithKeys(fn (mixed $id): array => [(string) $id => true])
            ->all();

        return self::$stateIds;
    }

    public static function flushStateIdCache(): void
    {
        self::$stateIds = null;
    }
}
