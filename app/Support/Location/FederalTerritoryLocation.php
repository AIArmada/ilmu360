<?php

namespace App\Support\Location;

use App\Models\State;

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
     * @var array<int, bool>|null
     */
    private static ?array $stateIds = null;

    public static function isFederalTerritoryStateId(int|string|null $stateId): bool
    {
        if (! filled($stateId)) {
            return false;
        }

        $resolvedStateId = (int) $stateId;

        if ($resolvedStateId <= 0) {
            return false;
        }

        return self::stateIds()[$resolvedStateId] ?? false;
    }

    public static function isFederalTerritoryStateName(?string $name): bool
    {
        if (! is_string($name)) {
            return false;
        }

        return in_array(mb_strtolower(trim($name)), self::STATE_NAME_KEYS, true);
    }

    /**
     * @return array<int, bool>
     */
    public static function stateIds(): array
    {
        if (self::$stateIds !== null) {
            return self::$stateIds;
        }

        self::$stateIds = State::query()
            ->where('country_id', PreferredCountryResolver::MALAYSIA_ID)
            ->whereIn('name', [
                'Kuala Lumpur',
                'Putrajaya',
                'Labuan',
                'Wilayah Persekutuan Kuala Lumpur',
                'Wilayah Persekutuan Putrajaya',
                'Wilayah Persekutuan Labuan',
            ])
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();

        return self::$stateIds;
    }

    public static function flushStateIdCache(): void
    {
        self::$stateIds = null;
    }
}
