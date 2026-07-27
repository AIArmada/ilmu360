<?php

namespace App\Support\Location;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\State;

final class AddressHierarchyFormatter
{
    /**
     * @param  list<'city'|'district'|'subdistrict'|'locality'|'state'>  $order
     * @return list<string>
     */
    public static function parts(
        ?Address $address,
        array $order = ['city', 'locality', 'subdistrict', 'district', 'state'],
    ): array {
        $assignments = AddressAssignments::forAddress($address);
        $areas = [];

        foreach ($assignments as $role => $areaId) {
            $areas[$role] = $address?->relationLoaded('areaAssignments')
                ? self::loadedAreaName($address, $role)
                : self::areaName($areaId);
        }

        $stateName = self::textAttribute($address, 'state');

        if ($stateName === null && $address?->relationLoaded('state')) {
            $stateName = self::normalizePart($address->getRelation('state')?->name);
        }

        if ($stateName === null && is_string($address?->state_id)) {
            $state = State::query()->find($address->state_id);
            $stateName = $state instanceof State ? self::normalizePart($state->name) : null;
        }

        $availableParts = [
            'city' => self::textAttribute($address, 'city'),
            'locality' => $areas[AddressAssignments::POSTAL_LOCALITY] ?? null,
            'subdistrict' => $areas[AddressAssignments::ADMINISTRATIVE_SUBDIVISION] ?? null,
            'district' => $areas[AddressAssignments::ADMINISTRATIVE_DISTRICT] ?? null,
            'state' => $stateName,
        ];

        $parts = [];

        foreach ($order as $key) {
            $part = $availableParts[$key] ?? null;

            if ($part === null) {
                continue;
            }

            $previous = $parts[array_key_last($parts)] ?? null;

            if (is_string($previous) && mb_strtolower($previous) === mb_strtolower($part)) {
                continue;
            }

            $parts[] = $part;
        }

        return $parts;
    }

    /** @return array{street: ?string, locality: ?string, regional: ?string} */
    public static function displayLines(?Address $address): array
    {
        if (! $address instanceof Address) {
            return ['street' => null, 'locality' => null, 'regional' => null];
        }

        $parts = self::parts($address);
        $street = implode(', ', array_filter([$address->line1, $address->line2]));
        $locality = implode(', ', array_filter([$parts[0] ?? self::textAttribute($address, 'city'), $address->postcode]));
        $regional = count($parts) > 1 ? implode(', ', array_slice($parts, 1)) : (self::textAttribute($address, 'state') ?? '');

        return [
            'street' => $street !== '' ? $street : null,
            'locality' => $locality !== '' ? $locality : null,
            'regional' => $regional !== '' ? $regional : null,
        ];
    }

    /** @param list<'city'|'district'|'subdistrict'|'locality'|'state'> $order */
    public static function format(?Address $address, array $order = ['city', 'locality', 'subdistrict', 'district', 'state'], string $separator = ', '): string
    {
        return implode($separator, self::parts($address, $order));
    }

    private static function loadedAreaName(Address $address, string $role): ?string
    {
        foreach ($address->getRelation('areaAssignments') as $assignment) {
            if ($assignment->role === $role) {
                return self::normalizePart($assignment->getRelation('area')?->name);
            }
        }

        return null;
    }

    private static function areaName(string $areaId): ?string
    {
        $area = AddressArea::query()->find($areaId);

        return $area instanceof AddressArea ? self::normalizePart($area->name) : null;
    }

    private static function normalizePart(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function textAttribute(?Address $address, string $attribute): ?string
    {
        if (! $address instanceof Address) {
            return null;
        }

        $value = $address->getRawOriginal($attribute);

        return is_string($value) ? self::normalizePart($value) : self::normalizePart($address->getAttribute($attribute));
    }
}
