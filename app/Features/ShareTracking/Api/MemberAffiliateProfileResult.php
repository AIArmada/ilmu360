<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Result of resolving a member profile for new share credit. */
final readonly class MemberAffiliateProfileResult
{
    public function __construct(
        public string $affiliateId,
        public bool $eligible,
    ) {}
}
