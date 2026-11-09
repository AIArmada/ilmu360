<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

use Carbon\CarbonImmutable;

final readonly class RecordSignupAttributionInput
{
    public function __construct(
        public string $accountId,
        public SignupAttributionSourceType $sourceType,
        public SignupAttributionStatus $status,
        public ?string $referrerAccountId = null,
        public ?string $affiliateId = null,
        public ?string $affiliateLinkId = null,
        public ?string $affiliateAttributionId = null,
        public ?string $referralInviteId = null,
        public ?string $inviteeContactHash = null,
        public ?CarbonImmutable $signedUpAt = null,
    ) {}
}
