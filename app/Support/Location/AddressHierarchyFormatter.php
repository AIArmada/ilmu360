<?php

namespace App\Support\Location;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\State;

class AddressHierarchyFormatter
{
    /**
     * @var list<string>
     */
    private const array STATE_HIDDEN_DISTRICTS = ['kuala lumpur', 'putrajaya', 'labuan'];

    /**
     * @param  list<'city'|'district'|'state'>  $order
     * @return list<string>
     */
    public static function parts(?Address $address, array $order = ['city', 'district', 'state']): array
    {
        // Product storage: admin_area_1 = district, admin_area_2 = subdistrict.
        $districtName = self::areaName($address?->admin_area_1_id);
        $subdistrictName = self::areaName($address?->admin_area_2_id);
        $stateName = self::normalizePart($address?->state);

        if ($stateName === null && is_string($address?->state_id) && $address->state_id !== '') {
            $state = State::query()->find($address->state_id);
            $stateName = $state instanceof State ? self::normalizePart($state->name) : null;
        }

        if ($stateName === null && is_string($address?->admin_area_1_id)) {
            $district = AddressArea::query()->find($address->admin_area_1_id);
            if ($district instanceof AddressArea && is_string($district->parent_id)) {
                $stateName = self::areaName($district->parent_id);
            }
        }

        if ($stateName === null && is_string($address?->admin_area_2_id)) {
            $subdistrict = AddressArea::query()->find($address->admin_area_2_id);
            if ($subdistrict instanceof AddressArea && is_string($subdistrict->parent_id)) {
                $parent = AddressArea::query()->find($subdistrict->parent_id);
                if ($parent instanceof AddressArea && (int) $parent->level === 1) {
                    $stateName = self::normalizePart($parent->name);
                } elseif ($parent instanceof AddressArea && is_string($parent->parent_id)) {
                    $stateName = self::areaName($parent->parent_id);
                }
            }
        }

        if (is_string($stateName) && in_array(mb_strtolower($stateName), self::STATE_HIDDEN_DISTRICTS, true)) {
            $stateName = null;
        }

        $cityName = $subdistrictName ?? $districtName ?? self::normalizePart($address?->city);

        $availableParts = [
            'city' => $cityName,
            'district' => $districtName,
            'state' => $stateName,
        ];

        $parts = [];

        foreach ($order as $key) {
            $part = $availableParts[$key] ?? null;

            if ($part === null) {
                continue;
            }

            $lastKey = array_key_last($parts);
            $previousPart = $lastKey !== null ? $parts[$lastKey] : null;

            if (is_string($previousPart) && mb_strtolower($previousPart) === mb_strtolower($part)) {
                continue;
            }

            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * @return array{street: ?string, locality: ?string, regional: ?string}
     */
    public static function displayLines(?Address $address): array
    {
        if (! $address instanceof Address) {
            return [
                'street' => null,
                'locality' => null,
                'regional' => null,
            ];
        }

        $parts = self::parts($address);
        $streetAddressLine = implode(', ', array_filter([
            $address->line1,
            $address->line2,
        ]));

        if ($parts !== []) {
            $localityAddressLine = implode(', ', array_filter([
                $parts[0] ?? null,
                $address->postcode,
            ]));
            $regionalAddressLine = count($parts) > 1 ? implode(', ', array_slice($parts, 1)) : '';
        } else {
            $localityAddressLine = implode(', ', array_filter([
                $address->city,
                $address->postcode,
            ]));
            $regionalAddressLine = filled($address->state) ? (string) $address->state : '';
        }

        return [
            'street' => $streetAddressLine !== '' ? $streetAddressLine : null,
            'locality' => $localityAddressLine !== '' ? $localityAddressLine : null,
            'regional' => $regionalAddressLine !== '' ? $regionalAddressLine : null,
        ];
    }

    /**
     * @param  list<'city'|'district'|'state'>  $order
     */
    public static function format(?Address $address, array $order = ['city', 'district', 'state'], string $separator = ', '): string
    {
        return implode($separator, self::parts($address, $order));
    }

    private static function normalizePart(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function areaName(?string $areaId): ?string
    {
        if (! is_string($areaId) || $areaId === '') {
            return null;
        }

        $area = AddressArea::query()->find($areaId);

        return $area instanceof AddressArea ? self::normalizePart($area->name) : null;
    }
}
