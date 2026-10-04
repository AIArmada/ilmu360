<?php

declare(strict_types=1);

namespace App\Data\Prayer;

use InvalidArgumentException;

/**
 * One day's prayer-times lookup.
 *
 * Location carries BOTH zone and coordinates (V2.2): zone-keyed providers
 * (JAKIM) consume `zone`, coordinate providers (Ummah, Aladhan) consume
 * `latitude`/`longitude`. Timezone is always explicit, never server-default.
 */
final readonly class PrayerQuery
{
    public string $countryCode;

    public function __construct(
        string $countryCode,
        public string $date,
        public string $timezone,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $zone = null,
        public ?string $method = null,
        public ?string $madhab = null,
    ) {
        $countryCode = strtoupper(trim($countryCode));

        if (strlen($countryCode) !== 2) {
            throw new InvalidArgumentException("Country code must be ISO2, got [{$countryCode}].");
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) !== 1 || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new InvalidArgumentException("Date must be a valid Y-m-d, got [{$date}].");
        }

        if (trim($timezone) === '') {
            throw new InvalidArgumentException('Timezone must be an explicit IANA identifier.');
        }

        $this->countryCode = $countryCode;
    }
}
