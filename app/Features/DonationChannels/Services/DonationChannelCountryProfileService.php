<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Services;

use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\CommerceSupport\Features\ReferenceData\Models\Currency;
use App\Features\DonationChannels\Api\Data\DonationBankCatalog;
use App\Features\DonationChannels\Api\Data\DonationChannelCountryProfile;
use App\Features\DonationChannels\Api\DonationBankCatalogs;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\DonationChannels\Api\DonationChannelOwnerCountry;
use App\Platform\ExtensionRegistry;
use App\Shared\Cache\SelectionCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final readonly class DonationChannelCountryProfileService implements DonationChannelCountryProfiles
{
    /**
     * @var list<string>
     */
    private const array MALAYSIAN_METHODS = ['bank_account', 'duitnow', 'ewallet'];

    /**
     * @var list<string>
     */
    private const array INTERNATIONAL_METHODS = ['bank_account'];

    public function __construct(
        private SelectionCatalogCache $catalogCache,
        private AddressCountryResolver $countries,
        private DonationBankCatalogs $bankCatalogs,
        private ExtensionRegistry $extensions,
    ) {}

    public function forCountryId(?string $countryId): DonationChannelCountryProfile
    {
        return $this->forCountryCode($this->countryCodeForId($countryId));
    }

    public function forCountryCode(?string $countryCode): DonationChannelCountryProfile
    {
        $countryCode = is_string($countryCode) ? strtoupper(trim($countryCode)) : null;
        $banks = $this->bankCatalogs->forCountryCode($countryCode);

        if ($countryCode === null || $countryCode === '') {
            return new DonationChannelCountryProfile(null, null, [], $banks);
        }

        return new DonationChannelCountryProfile(
            $countryCode,
            $this->currencyForCountryCode($countryCode),
            $this->methodsForCountryCode($countryCode, $banks),
            $banks,
            supports_routing_codes: $banks->supports_routing_codes,
        );
    }

    public function forOwner(mixed $owner): DonationChannelCountryProfile
    {
        return $this->forCountryId($this->ownerCountryId($owner));
    }

    /**
     * @return array<string, string>
     */
    public function currencyOptions(): array
    {
        $options = $this->catalogCache->rememberAddressOptions(
            'currencies',
            static fn (): array => Currency::query()->orderBy('code')->pluck('code', 'code')->all(),
        );

        $options['MYR'] ??= 'MYR';

        ksort($options);

        return $options;
    }

    private function ownerCountryId(mixed $owner): ?string
    {
        if (! is_object($owner)) {
            return null;
        }

        if (method_exists($owner, 'primaryAddress')) {
            return $this->primaryAddressCountryId($owner);
        }

        return $this->extensionCountryId($owner);
    }

    private function primaryAddressCountryId(object $owner): ?string
    {
        if (method_exists($owner, 'unsetRelation')) {
            $owner->unsetRelation('addresses');
        }

        $address = $owner->primaryAddress();

        if (! $address instanceof Model) {
            return null;
        }

        $countryId = $address->getAttribute('country_id');

        return is_string($countryId) && $countryId !== '' ? $countryId : null;
    }

    private function extensionCountryId(object $owner): ?string
    {
        if (! method_exists($owner, 'getMorphClass') || ! method_exists($owner, 'getKey')) {
            return null;
        }

        $ownerType = (string) $owner->getMorphClass();

        foreach ($this->extensions->resolve('donation-channel.owner-country', DonationChannelOwnerCountry::class) as $resolver) {
            if ($resolver->supports($ownerType)) {
                return $resolver->countryIdFor($ownerType, (string) $owner->getKey());
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function methodsForCountryCode(string $countryCode, DonationBankCatalog $banks): array
    {
        if (! $banks->hasEntries()) {
            return [];
        }

        return $countryCode === 'MY' ? self::MALAYSIAN_METHODS : self::INTERNATIONAL_METHODS;
    }

    private function countryCodeForId(?string $countryId): ?string
    {
        $countryId = is_string($countryId) ? trim($countryId) : '';

        if ($countryId === '' || ! Str::isUuid($countryId)) {
            return null;
        }

        return $this->catalogCache->rememberAddressValue(
            "country-iso2:{$countryId}",
            fn (): ?string => $this->iso2ForCountryId($countryId),
        );
    }

    private function iso2ForCountryId(string $countryId): ?string
    {
        $country = $this->countries->resolve($countryId);

        if (! $country instanceof Model) {
            return null;
        }

        $iso2 = $country->getAttribute('iso2');

        return is_string($iso2) && $iso2 !== '' ? $iso2 : null;
    }

    private function currencyForCountryCode(string $countryCode): ?string
    {
        if ($countryCode === 'MY') {
            return 'MYR';
        }

        return $this->catalogCache->rememberAddressValue(
            "country-currency:{$countryCode}",
            fn (): ?string => $this->linkedCurrencyForCountryCode($countryCode),
        );
    }

    private function linkedCurrencyForCountryCode(string $countryCode): ?string
    {
        $country = $this->countries->resolve($countryCode);

        if (! $country instanceof Model || ! method_exists($country, 'currencies')) {
            return null;
        }

        $currency = $country->currencies()->orderBy('code')->pluck('code')->first();

        return is_string($currency) ? $currency : null;
    }
}
