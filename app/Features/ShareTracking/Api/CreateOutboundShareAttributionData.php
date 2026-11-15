<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Scalar facts for creating or reusing an outbound-share attribution. */
final readonly class CreateOutboundShareAttributionData
{
    public function __construct(
        public string $affiliateId,
        public string $affiliateCode,
        public ?string $userId,
        public string $affiliateLinkId,
        public ?string $subjectType,
        public string $lookupSubjectKey,
        public ?string $subjectKey,
        public ?string $subjectId,
        public string $subjectInstance,
        public ?string $origin,
        public ?string $subjectTitleSnapshot,
        public string $channel,
    ) {}
}
