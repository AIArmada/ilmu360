<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Affiliates\Services\Payouts\PayoutProcessorFactory;
use AIArmada\Rewards\Contracts\RewardPayoutMethodCatalog;

/**
 * Affiliates-backed payout method catalog: serves the installed payout
 * processors (manual rails plus automated providers) with their live fee
 * schedules and credential prerequisites. Fee math and destination-shape
 * validation always come from the installed processors — never guessed.
 */
final class AffiliatesRewardPayoutMethodCatalog implements RewardPayoutMethodCatalog
{
    public function __construct(
        private readonly PayoutProcessorFactory $factory,
        private readonly RewardPayoutProcessorCatalog $catalog,
    ) {}

    public function registered(): array
    {
        return $this->catalog->registered();
    }

    public function isRegistered(string $method): bool
    {
        return $this->catalog->isRegistered($method);
    }

    public function isManual(string $method): bool
    {
        return $this->catalog->isManual($method);
    }

    public function isSupported(string $method): bool
    {
        return $this->catalog->isSupported($method);
    }

    public function describe(): array
    {
        return $this->catalog->describe();
    }

    public function missingCredentials(string $method): array
    {
        return $this->catalog->missingCredentials($method);
    }

    public function feeFor(string $method, int $amountMinor, string $currency): int
    {
        return $this->factory->make($method)->getFees($amountMinor, $currency);
    }

    public function detailsFor(string $method, string $accountRef, array $audit = []): array
    {
        return match ($method) {
            'paypal' => [...$audit, 'email' => $accountRef],
            'stripe_connect' => [...$audit, 'stripe_account_id' => $accountRef],
            default => $audit,
        };
    }

    public function destinationRefFor(string $method, array $details): ?string
    {
        $key = match ($method) {
            'paypal' => 'email',
            'stripe_connect' => 'stripe_account_id',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $ref = $details[$key] ?? null;

        return is_string($ref) ? $ref : null;
    }

    public function validateDestination(string $method, array $details): array
    {
        return $this->factory->make($method)->validateDetails($details);
    }
}
