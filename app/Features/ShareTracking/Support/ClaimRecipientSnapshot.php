<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Support;

/** Immutable member-affiliate identity that receives a guest claim. */
final readonly class ClaimRecipientSnapshot
{
    public function __construct(
        public string $affiliateId,
    ) {}
}
