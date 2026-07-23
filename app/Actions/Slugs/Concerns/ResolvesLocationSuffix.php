<?php

declare(strict_types=1);

namespace App\Actions\Slugs\Concerns;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use Illuminate\Support\Str;

trait ResolvesLocationSuffix
{
    private function slugSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::slug($value);

        return $segment !== '' ? $segment : null;
    }

    private function countryCodeSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::lower(trim($value));

        return $segment !== '' ? $segment : null;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function uuidValue(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function areaName(mixed $areaId): ?string
    {
        $areaId = $this->uuidValue($areaId);

        if ($areaId === null) {
            return null;
        }

        $resolved = AddressArea::query()->whereKey($areaId)->value('name');

        return is_string($resolved) && trim($resolved) !== '' ? $resolved : null;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function resolveCountryCode(array $address, bool $preferLiteral = false): ?string
    {
        if ($preferLiteral) {
            $countryCode = $address['country_code'] ?? null;

            if (is_string($countryCode) && trim($countryCode) !== '') {
                return trim($countryCode);
            }
        }

        $countryId = $this->uuidValue($address['country_id'] ?? null);

        if ($countryId !== null) {
            $resolved = AddressCountry::query()->whereKey($countryId)->value('iso2');

            if (is_string($resolved) && trim($resolved) !== '') {
                return trim($resolved);
            }
        }

        if (! $preferLiteral) {
            $countryCode = $address['country_code'] ?? null;

            if (is_string($countryCode) && trim($countryCode) !== '') {
                return trim($countryCode);
            }
        }

        return null;
    }
}
