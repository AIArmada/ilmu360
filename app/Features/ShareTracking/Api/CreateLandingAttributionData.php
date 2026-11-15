<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Scalar facts for creating a landing attribution. */
final readonly class CreateLandingAttributionData
{
    /**
     * @param  array<string, string>|null  $campaignMetadata
     */
    public function __construct(
        public string $affiliateId,
        public string $affiliateCode,
        public ?string $subjectType,
        public string $subjectKey,
        public ?string $subjectId,
        public ?string $subjectTitleSnapshot,
        public string $cookieValue,
        public string $landingUrl,
        public ?string $ipAddressHash,
        public string $affiliateLinkId,
        public string $visitorKey,
        public ?string $channel,
        public string $origin,
        public ?string $sharerUserId,
        public ?array $campaignMetadata,
        public ?string $ownerType,
        public ?string $ownerId,
    ) {}
}
