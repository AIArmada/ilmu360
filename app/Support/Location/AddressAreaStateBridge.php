<?php

declare(strict_types=1);

namespace App\Support\Location;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\State;
use Illuminate\Support\Str;

/**
 * Links package State rows to optional AddressArea level-1 tree nodes
 * used only as parents for district/subdistrict cascades.
 */
final class AddressAreaStateBridge
{
    /**
     * Resolve the AddressArea level-1 node that matches a State (same country).
     */
    public static function areaIdForState(State|string|null $state): ?string
    {
        $state = self::resolveState($state);

        if (! $state instanceof State) {
            return null;
        }

        $candidates = self::nameCandidates($state);

        if ($candidates === []) {
            return null;
        }

        $area = AddressArea::query()
            ->where('country_id', $state->country_id)
            ->where('level', 1)
            ->where(function ($query) use ($candidates, $state): void {
                $query->whereIn('name', $candidates);

                if (is_string($state->code) && $state->code !== '') {
                    $query->orWhere('code', $state->code);
                }
            })
            ->orderBy('name')
            ->first();

        return $area instanceof AddressArea ? (string) $area->getKey() : null;
    }

    /**
     * Resolve a State from an AddressArea level-1 (or walk up from district/subdistrict).
     */
    public static function stateIdForArea(AddressArea|string|null $area): ?string
    {
        $area = self::resolveArea($area);

        if (! $area instanceof AddressArea) {
            return null;
        }

        $level1 = $area;

        if ((int) $area->level === 2 && is_string($area->parent_id)) {
            $level1 = AddressArea::query()->find($area->parent_id) ?? $area;
        }

        if ((int) $area->level === 3 && is_string($area->parent_id)) {
            $parent = AddressArea::query()->find($area->parent_id);

            if ($parent instanceof AddressArea && (int) $parent->level === 2 && is_string($parent->parent_id)) {
                $level1 = AddressArea::query()->find($parent->parent_id) ?? $parent;
            } elseif ($parent instanceof AddressArea && (int) $parent->level === 1) {
                $level1 = $parent;
            }
        }

        if ((int) $level1->level !== 1) {
            return null;
        }

        $candidates = self::normalizeNames([
            $level1->name,
            $level1->native_name,
            $level1->code,
        ]);

        if ($candidates === []) {
            return null;
        }

        $state = State::query()
            ->where('country_id', $level1->country_id)
            ->where(function ($query) use ($candidates, $level1): void {
                $query->whereIn('name', $candidates)
                    ->orWhereIn('label', $candidates);

                if (is_string($level1->code) && $level1->code !== '') {
                    $query->orWhere('code', $level1->code);
                }
            })
            ->orderBy('name')
            ->first();

        return $state instanceof State ? (string) $state->getKey() : null;
    }

    /**
     * @return list<string>
     */
    private static function nameCandidates(State $state): array
    {
        $names = self::normalizeNames([
            $state->name,
            $state->label,
            $state->code,
        ]);

        $expanded = $names;

        foreach ($names as $name) {
            $lower = mb_strtolower($name);

            if (str_contains($lower, 'kuala lumpur') || $lower === 'wp kuala lumpur') {
                $expanded[] = 'Kuala Lumpur';
                $expanded[] = 'WP Kuala Lumpur';
                $expanded[] = 'Wilayah Persekutuan Kuala Lumpur';
            }

            if (str_contains($lower, 'putrajaya') || $lower === 'wp putrajaya') {
                $expanded[] = 'Putrajaya';
                $expanded[] = 'WP Putrajaya';
                $expanded[] = 'Wilayah Persekutuan Putrajaya';
            }

            if (str_contains($lower, 'labuan') || $lower === 'wp labuan') {
                $expanded[] = 'Labuan';
                $expanded[] = 'WP Labuan';
                $expanded[] = 'Wilayah Persekutuan Labuan';
            }

            if (str_starts_with($lower, 'wp ')) {
                $expanded[] = trim(Str::after($name, 'WP '));
                $expanded[] = 'Wilayah Persekutuan '.trim(Str::after($name, 'WP '));
            }

            if (str_starts_with($lower, 'wilayah persekutuan ')) {
                $expanded[] = trim(Str::after($name, 'Wilayah Persekutuan '));
                $expanded[] = 'WP '.trim(Str::after($name, 'Wilayah Persekutuan '));
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private static function normalizeNames(array $values): array
    {
        $names = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed !== '') {
                $names[] = $trimmed;
            }
        }

        return array_values(array_unique($names));
    }

    private static function resolveState(State|string|null $state): ?State
    {
        if ($state instanceof State) {
            return $state;
        }

        if (! is_string($state) || $state === '') {
            return null;
        }

        $found = State::query()->find($state);

        return $found instanceof State ? $found : null;
    }

    private static function resolveArea(AddressArea|string|null $area): ?AddressArea
    {
        if ($area instanceof AddressArea) {
            return $area;
        }

        if (! is_string($area) || $area === '') {
            return null;
        }

        $found = AddressArea::query()->find($area);

        return $found instanceof AddressArea ? $found : null;
    }
}
