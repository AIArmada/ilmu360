<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Scalar facts required to record an outbound-share touchpoint.
 */
final readonly class OutboundShareTouchpointData
{
    public function __construct(
        public string $attributionId,
        public string $affiliateId,
        public string $affiliateCode,
        public ?string $ipAddressHash,
        public string $linkId,
        public ?string $subjectType,
        public ?string $subjectKey,
        public ?string $subjectId,
        public string $subjectInstance,
        public ?string $subjectTitleSnapshot,
        public ?string $visitorKey,
        public string $channel,
        public ?string $origin,
    ) {}
}
