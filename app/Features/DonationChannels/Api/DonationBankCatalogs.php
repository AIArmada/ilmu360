<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api;

use App\Features\DonationChannels\Api\Data\DonationBankCatalog;

/**
 * Trusted bank catalogs keyed by country.
 *
 * Implementations only cover countries whose banks can be resolved from a
 * trusted installed source; everything else resolves to an empty catalog.
 */
interface DonationBankCatalogs
{
    public function forCountryCode(?string $countryCode): DonationBankCatalog;
}
