<?php

declare(strict_types=1);

namespace App\Support\Location;

use AIArmada\Addressing\Models\Address;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class AddressAssignments
{
    public const string POSTAL_LOCALITY = 'postal_locality';

    public const string ADMINISTRATIVE_DISTRICT = 'administrative_district';

    public const string ADMINISTRATIVE_SUBDIVISION = 'administrative_subdivision';

    /**
     * @return array<string, string>
     */
    public static function forAddress(?Address $address): array
    {
        if (! $address instanceof Address) {
            return [];
        }

        return $address->areaAssignments()
            ->where('is_primary', true)
            ->pluck('address_area_id', 'role')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    public static function id(?Address $address, string $role): ?string
    {
        $id = self::forAddress($address)[$role] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $assignments
     * @return array<string, string>
     */
    public static function normalize(array $assignments): array
    {
        $normalized = [];

        foreach ($assignments as $role => $areaId) {
            if (! is_string($role) || ! is_string($areaId)) {
                continue;
            }

            $role = trim($role);
            $areaId = trim($areaId);

            if ($role !== '' && $areaId !== '') {
                $normalized[$role] = $areaId;
            }
        }

        return $normalized;
    }

    /**
     * Apply role-based address assignments to a relation query.
     *
     * @param  array<string, string>  $assignments
     */
    /**
     * @param  Builder<Model>  $query
     * @param  array<string, string>  $assignments
     * @return Builder<Model>
     */
    public static function apply(Builder $query, array $assignments, string $relation = 'addresses'): Builder
    {
        foreach (self::normalize($assignments) as $role => $areaId) {
            $query->whereHas($relation.'.areaAssignments', static function (Builder $assignmentQuery) use ($role, $areaId): void {
                $assignmentQuery
                    ->where('role', $role)
                    ->where('address_area_id', $areaId)
                    ->where('is_primary', true);
            });
        }

        return $query;
    }
}
