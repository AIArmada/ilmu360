<?php

namespace App\Support\Location;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaStateBridge;

class AddressHierarchyFormatter
{
    /**
     * @param  list<'city'|'district'|'subdistrict'|'state'|'area_1'|'area_2'|'area_3'|'area_4'>  $order
     * @return list<string>
     */
    public static function parts(
        ?Address $address,
        array $order = ['city', 'area_4', 'area_3', 'area_2', 'area_1', 'state'],
    ): array {
        $areaNames = [];

        foreach (range(1, 4) as $slot) {
            $areaNames[$slot] = self::areaName($address?->getAttribute("admin_area_{$slot}_id"));
        }

        $stateName = self::normalizePart($address?->state);

        if ($stateName === null && is_string($address?->state_id) && $address->state_id !== '') {
            $state = State::query()->find($address->state_id);
            $stateName = $state instanceof State ? self::normalizePart($state->name) : null;
        }

        if ($stateName === null) {
            foreach (array_reverse($areaNames, true) as $slot => $areaName) {
                if ($areaName === null) {
                    continue;
                }

                $areaId = $address?->getAttribute("admin_area_{$slot}_id");
                $stateId = AddressAreaStateBridge::stateIdForArea(is_string($areaId) ? $areaId : null);

                if ($stateId !== null) {
                    $state = State::query()->find($stateId);
                    $stateName = $state instanceof State ? self::normalizePart($state->name) : null;
                }

                if ($stateName !== null) {
                    break;
                }
            }
        }

        $cityName = self::normalizePart($address?->city);

        $availableParts = [
            'city' => $cityName,
            'district' => $areaNames[1],
            'subdistrict' => $areaNames[2],
            'area_1' => $areaNames[1],
            'area_2' => $areaNames[2],
            'area_3' => $areaNames[3],
            'area_4' => $areaNames[4],
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
     * @param  list<'city'|'district'|'subdistrict'|'state'|'area_1'|'area_2'|'area_3'|'area_4'>  $order
     */
    public static function format(
        ?Address $address,
        array $order = ['city', 'area_4', 'area_3', 'area_2', 'area_1', 'state'],
        string $separator = ', ',
    ): string {
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
