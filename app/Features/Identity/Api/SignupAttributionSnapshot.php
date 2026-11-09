<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

use Carbon\CarbonImmutable;

final readonly class SignupAttributionSnapshot
{
    public function __construct(
        public string $id,
        public string $accountId,
        public ?string $referrerAccountId,
        public SignupAttributionSourceType $sourceType,
        public SignupAttributionStatus $status,
        public ?string $affiliateId,
        public ?string $affiliateLinkId,
        public ?string $affiliateAttributionId,
        public ?string $referralInviteId,
        public ?string $inviteeContactHash,
        public ?CarbonImmutable $signedUpAt,
        public ?CarbonImmutable $confirmedAt,
    ) {}
}
