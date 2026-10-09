<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Ledgers\Support;

use App\Contracts\Rewards\RewardReservationResolver;

/**
 * Explicit fake reservation resolver for ledger tests.
 *
 * FIXTURE attestation only: scripted per-key void approvals let tests exercise
 * real persisted reversal transactions (locks, voids, races) without a host
 * provider integration. Passing tests prove domain behavior against scripted
 * attestation — never host fulfillment correctness, which requires the
 * accepted provider adapters. Unseeded keys answer false, exactly like the
 * null resolver.
 */
final class FakeRewardReservationResolver implements RewardReservationResolver
{
    /**
     * @var array<string, true>
     */
    private array $voidable = [];

    /**
     * @var list<string>
     */
    private array $reads = [];

    /**
     * @param  list<string>  $voidableKeys
     */
    public function __construct(array $voidableKeys = [])
    {
        foreach ($voidableKeys as $key) {
            $this->voidable[$key] = true;
        }
    }

    public function seedVoidable(string $reservationKey): void
    {
        $this->voidable[$reservationKey] = true;
    }

    public function mayVoidReservation(string $reservationKey): bool
    {
        $this->reads[] = $reservationKey;

        return $this->voidable[$reservationKey] ?? false;
    }

    /**
     * @return list<string>
     */
    public function readKeys(): array
    {
        return $this->reads;
    }
}
