<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api\Data;

final readonly class DonationChannelCountryProfile
{
    /**
     * @param  list<string>  $methods
     */
    public function __construct(
        public ?string $country_code,
        public ?string $default_currency,
        public array $methods,
        public DonationBankCatalog $banks,
        public bool $supports_routing_codes = false,
    ) {}

    public function supports(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    public function isMalaysia(): bool
    {
        return $this->country_code === 'MY';
    }
}
