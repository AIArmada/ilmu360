<?php

declare(strict_types=1);

namespace App\Support\Rewards;

/**
 * Exact integer point-bucket reconciliation from persisted movements.
 *
 * Buckets, per lot:
 * - credited: sum(credit) + sum(refund-as-credit) + positive adjust
 * - spent: sum(debit)
 * - reserved: sum(active reserve) where a reserve is active when no linked
 *   release/expire/debit-capture movement references it
 * - expired: sum(expire)
 * - pending: credited lots whose available_at is still in the future
 * - available: credited - spent - reserved - expired (matured lots only)
 *
 * Reserved points are never spendable and never double-counted as spent:
 * a debit either spends available directly or captures a reservation
 * (reducing reserved, increasing spent — never both at once).
 */
final readonly class RewardPointBuckets
{
    public function __construct(
        public int $pending = 0,
        public int $available = 0,
        public int $reserved = 0,
        public int $spent = 0,
        public int $expired = 0,
    ) {}

    public function total(): int
    {
        return $this->pending + $this->available + $this->reserved + $this->spent + $this->expired;
    }

    /**
     * @param  array{pending?: int, available?: int, reserved?: int, spent?: int, expired?: int}  $buckets
     */
    public static function fromArray(array $buckets): self
    {
        return new self(
            pending: (int) ($buckets['pending'] ?? 0),
            available: (int) ($buckets['available'] ?? 0),
            reserved: (int) ($buckets['reserved'] ?? 0),
            spent: (int) ($buckets['spent'] ?? 0),
            expired: (int) ($buckets['expired'] ?? 0),
        );
    }

    /**
     * @return array{pending: int, available: int, reserved: int, spent: int, expired: int}
     */
    public function toArray(): array
    {
        return [
            'pending' => $this->pending,
            'available' => $this->available,
            'reserved' => $this->reserved,
            'spent' => $this->spent,
            'expired' => $this->expired,
        ];
    }
}
