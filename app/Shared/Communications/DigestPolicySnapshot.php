<?php

declare(strict_types=1);

namespace App\Shared\Communications;

/**
 * Immutable, scalar digest policy snapshot.
 *
 * Cadence is the stored value (`off`, `instant`, `daily` or `weekly`) and the
 * trigger is the stored trigger key (for example `share_gratitude_digest`).
 * Delivery time and weekly day come from the global digest configuration, not
 * per-recipient preferences.
 */
final readonly class DigestPolicySnapshot
{
    public function __construct(
        public bool $enabled,
        public string $cadence,
        public string $trigger,
        public string $timezone,
        public ?string $deliveryTime,
        public int $weeklyDay,
        public ?string $quietHoursStart,
        public ?string $quietHoursEnd,
    ) {}
}
