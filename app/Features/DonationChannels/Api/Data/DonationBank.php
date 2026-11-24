<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api\Data;

/**
 * One bank from a country-scoped catalog.
 *
 * The code is the canonical identifier scoped to the country UUID; the name
 * is the catalog display name, never caller supplied text. Retired entries
 * stay resolvable for stored donation channel references but are excluded
 * from new selection.
 */
final readonly class DonationBank
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $retired = false,
    ) {}
}
