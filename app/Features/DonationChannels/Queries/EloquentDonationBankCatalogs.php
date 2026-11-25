<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Queries;

use AIArmada\Addressing\Support\AddressCountryResolver;
use App\Features\DonationChannels\Api\Data\DonationBank as DonationBankEntry;
use App\Features\DonationChannels\Api\Data\DonationBankCatalog;
use App\Features\DonationChannels\Api\DonationBankCatalogs;
use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Features\DonationChannels\Models\DonationBank;

/**
 * Bank catalogs read straight from the admin-managed `donation_banks` table.
 *
 * The database is the sole runtime source: there is no in-code fallback bank
 * list, so an entry an administrator retired or deleted never reappears
 * through a resolver. Countries without rows resolve to an empty catalog,
 * except the territories in TERRITORY_FALLBACKS, which use their parent
 * country's banking system.
 */
final readonly class EloquentDonationBankCatalogs implements DonationBankCatalogs
{
    /**
     * Territories that share another country's banking system but have no
     * independent BIC records. The requested country code is preserved.
     *
     * @var array<string, string>
     */
    private const array TERRITORY_FALLBACKS = [
        'AX' => 'FI',
        'BL' => 'FR',
        'CC' => 'AU',
        'CX' => 'AU',
        'MF' => 'FR',
        'NF' => 'AU',
        'NU' => 'NZ',
        'PN' => 'NZ',
        'SJ' => 'NO',
        'TK' => 'NZ',
        'UM' => 'US',
        'VA' => 'IT',
    ];

    public function __construct(private AddressCountryResolver $countries) {}

    public function forCountryCode(?string $countryCode): DonationBankCatalog
    {
        $countryCode = is_string($countryCode) ? mb_strtoupper(trim($countryCode)) : '';

        if ($countryCode === '') {
            return new DonationBankCatalog(null);
        }

        $banks = $this->banksFor($countryCode);

        if ($banks === [] && isset(self::TERRITORY_FALLBACKS[$countryCode])) {
            $banks = $this->banksFor(self::TERRITORY_FALLBACKS[$countryCode]);
        }

        return new DonationBankCatalog(
            $countryCode,
            $banks,
            supports_routing_codes: $this->supportsRoutingCodes($countryCode),
        );
    }

    /**
     * @return list<DonationBankEntry>
     */
    private function banksFor(string $countryCode): array
    {
        $countryId = $this->countries->resolveId($countryCode);

        if ($countryId === null) {
            return [];
        }

        $entries = DonationBank::query()
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->orderBy('code')
            ->get()
            ->map(static fn (DonationBank $bank): DonationBankEntry => new DonationBankEntry(
                (string) $bank->getAttribute('code'),
                (string) $bank->getAttribute('name'),
                $bank->status === DonationBankStatus::Retired,
            ))
            ->all();

        return array_values($entries);
    }

    private function supportsRoutingCodes(string $countryCode): bool
    {
        $countries = config('donation-channels.routing_code_countries', []);

        if (! is_array($countries)) {
            return false;
        }

        return array_any($countries, fn ($candidate) => is_string($candidate) && mb_strtoupper(trim($candidate)) === $countryCode);
    }
}
