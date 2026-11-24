<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api\Data;

/**
 * Country-scoped bank catalog snapshot.
 *
 * An empty catalog means the country has no trusted bank data installed, so
 * bank-account donations stay unavailable instead of falling back to free
 * text. Selection surfaces only offer active entries; stored donation channel
 * references keep resolving retired entries so a retirement never rewrites an
 * existing payout. `supports_routing_codes` marks the countries whose bank
 * transfers carry an independent local clearing or branch code (a routing /
 * sort code) next to the account number.
 */
final readonly class DonationBankCatalog
{
    /**
     * @param  list<DonationBank>  $banks
     */
    public function __construct(
        public ?string $country_code,
        private array $banks = [],
        public bool $supports_routing_codes = false,
    ) {}

    /**
     * The selectable banks as canonical code => display name.
     *
     * Numeric codes (for example Singapore's FAST codes) become integer array
     * keys in PHP; callers that serialize must cast the key back to string.
     *
     * @return array<array-key, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->banks as $bank) {
            if (! $bank->retired) {
                $options[$bank->code] = $bank->name;
            }
        }

        return $options;
    }

    /**
     * Whether the country still has at least one selectable bank.
     */
    public function covers(): bool
    {
        return $this->options() !== [];
    }

    /**
     * Whether the country has catalog rows at all, retired entries included.
     *
     * Method availability follows this: a country with only retired entries
     * stops offering new bank selection, but its stored donation channels are
     * still validated against a supported method set.
     */
    public function hasEntries(): bool
    {
        return $this->banks !== [];
    }

    /**
     * Whether the code may be selected for a new or changed payout.
     */
    public function has(string $code): bool
    {
        return $this->find($code) instanceof DonationBank;
    }

    /**
     * Whether the code may stay on an already stored payout.
     *
     * Retired entries remain valid stored references so existing verified
     * channels are never silently rewritten by a retirement.
     */
    public function retains(string $code): bool
    {
        return $this->reference($code) instanceof DonationBank;
    }

    /**
     * Resolve a selectable (active) bank by code.
     */
    public function find(string $code): ?DonationBank
    {
        $code = $this->normalize($code);

        foreach ($this->banks as $bank) {
            if (! $bank->retired && $bank->code === $code) {
                return $bank;
            }
        }

        return null;
    }

    /**
     * Resolve any catalog entry by code, retired entries included.
     */
    public function reference(string $code): ?DonationBank
    {
        $code = $this->normalize($code);

        foreach ($this->banks as $bank) {
            if ($bank->code === $code) {
                return $bank;
            }
        }

        return null;
    }

    public function name(string $code): ?string
    {
        return $this->reference($code)?->name;
    }

    private function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
