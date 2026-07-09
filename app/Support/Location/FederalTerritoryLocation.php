<?php

declare(strict_types=1);

namespace App\Support\Location;

use AIArmada\Addressing\Models\State;

class FederalTerritoryLocation
{
    /**
     * @var list<string>
     */
    private const array STATE_NAME_KEYS = [
        'kuala lumpur',
        'putrajaya',
        'labuan',
        'wp kuala lumpur',
        'wp putrajaya',
        'wp labuan',
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

        $state = State::query()->whereKey($stateId)->first();

        if (! $state instanceof State) {
            return false;
        }

        $isFederalTerritory = self::isFederalTerritoryStateName($state->name)
            || self::isFederalTerritoryStateName($state->label);

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

        self::$stateIds = State::query()
            ->get(['id', 'name', 'label'])
            ->filter(fn (State $state): bool => self::isFederalTerritoryStateName($state->name)
                || self::isFederalTerritoryStateName($state->label))
            ->mapWithKeys(fn (State $state): array => [(string) $state->getKey() => true])
            ->all();

        return self::$stateIds;
    }

    public static function flushStateIdCache(): void
    {
        self::$stateIds = null;
    }
}
