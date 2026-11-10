<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

use DateTimeImmutable;

/** Immutable guest-affiliate claim facts needed by Identity attribution. */
final readonly class GuestClaimSnapshot
{
    public function __construct(
        public string $claimId,
        public string $affiliateId,
        public string $claimantType,
        public string $claimantId,
        public bool $active,
        public DateTimeImmutable $createdAt,
    ) {}
}
