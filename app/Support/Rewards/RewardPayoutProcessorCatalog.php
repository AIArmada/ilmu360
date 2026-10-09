<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Affiliates\Services\Payouts\ManualPayoutProcessor;
use AIArmada\Affiliates\Services\Payouts\PayoutProcessorFactory;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Real payout-processor discovery through the installed factory.
 *
 * Registered != supported: a processor is supported only when the factory
 * registers it AND its runtime prerequisites hold. Manual/bank-transfer
 * processors are operator-driven and supported once a verified payee
 * exists; automated providers additionally require the INSTALLED
 * credential keys (affiliates.payouts.* — the same keys the installed
 * processors read). Readiness surfaces never report a provider as
 * usable on enum membership or registration alone, and unknown
 * credential contracts report unsupported rather than guessing keys.
 */
final class RewardPayoutProcessorCatalog
{
    public function __construct(
        private readonly PayoutProcessorFactory $factory,
    ) {}

    /**
     * @return list<string> registered processor keys from the installed factory.
     */
    public function registered(): array
    {
        return array_values($this->factory->getAvailableProcessors());
    }

    public function isRegistered(string $processor): bool
    {
        return $this->factory->hasProcessor($processor);
    }

    public function isManual(string $processor): bool
    {
        // Derived from the installed factory registration, not a local
        // list: whatever the factory serves through the manual class is
        // operator-handled.
        try {
            return $this->factory->make($processor) instanceof ManualPayoutProcessor;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Automated providers require configured credentials before activation.
     * Manual processors require operator handling instead; credentials are
     * not applicable and never fabricated here.
     *
     * @return list<string> missing credential keys; empty means configured.
     */
    public function missingCredentials(string $processor): array
    {
        if ($this->isManual($processor)) {
            return [];
        }

        // The exact keys the installed processors read in their
        // constructors — verified against the installed package source.
        $required = match ($processor) {
            'stripe_connect' => ['affiliates.payouts.stripe.secret_key'],
            'paypal' => ['affiliates.payouts.paypal.client_id', 'affiliates.payouts.paypal.client_secret'],
            default => null,
        };

        // Unknown credential contract: unsupported, never guessed.
        if ($required === null) {
            return ["affiliates.payouts.{$processor} (unknown credential contract)"];
        }

        $missing = [];

        foreach ($required as $key) {
            $value = Config::get($key);

            if (! is_string($value) || mb_trim($value) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function isSupported(string $processor): bool
    {
        return $this->isRegistered($processor) && $this->missingCredentials($processor) === [];
    }

    /**
     * @return array<string, array{registered: bool, manual: bool, supported: bool, missing_credentials: list<string>}>
     */
    public function describe(): array
    {
        $described = [];

        foreach ($this->registered() as $processor) {
            $described[$processor] = [
                'registered' => true,
                'manual' => $this->isManual($processor),
                'supported' => $this->isSupported($processor),
                'missing_credentials' => $this->missingCredentials($processor),
            ];
        }

        return $described;
    }
}
