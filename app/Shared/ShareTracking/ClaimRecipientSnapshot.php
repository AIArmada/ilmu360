<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

/** Immutable member-affiliate identity that receives a guest claim. */
final readonly class ClaimRecipientSnapshot
{
    public function __construct(
        public string $affiliateId,
    ) {}
}
