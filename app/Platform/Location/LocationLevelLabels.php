<?php

declare(strict_types=1);

namespace App\Platform\Location;

use AIArmada\Addressing\Support\CountryAddressProfileResolver;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Resolves the translated label for a country's address storage level.
 *
 * Extracted from the shared form schema so generic location consumers
 * (directory listings, event filters, saved searches) depend on Platform
 * instead of the form-building layer.
 */
final class LocationLevelLabels
{
    /**
     * @param  array<string, ?string>  $areaIds
     */
    public static function label(
        int|string|null $countryId,
        string $storageColumn,
        string $fallback,
        int|string|null $stateId = null,
        array $areaIds = [],
    ): string {
        $countryId = self::normalizeLocationId($countryId);

        if ($countryId === null) {
            return $fallback;
        }

        $label = app(CountryAddressProfileResolver::class)->levelLabel(
            $countryId,
            $storageColumn,
            self::normalizeLocationId($stateId),
            $areaIds,
        );

        if ($label === null) {
            return $fallback;
        }

        if (Lang::hasForLocale($label)) {
            return __($label);
        }

        return implode(' / ', array_map(
            static fn (string $typeLabel): string => __($typeLabel),
            explode(' / ', $label),
        ));
    }

    private static function normalizeLocationId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        if (Str::isUuid($trimmed) || ctype_digit($trimmed)) {
            return $trimmed;
        }

        return null;
    }
}
