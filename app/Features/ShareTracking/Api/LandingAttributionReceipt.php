<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Scalar attribution facts consumed by browser selection, cookies, and visits.
 */
final readonly class LandingAttributionReceipt
{
    public function __construct(
        public string $attributionId,
        public string $cookieValue,
        public string $affiliateId,
        public string $affiliateCode,
        public ?string $source,
        public ?string $medium,
        public ?string $campaign,
        public ?string $term,
        public ?string $content,
        public ?string $subjectTitleSnapshot,
        public ?string $affiliateLinkId,
        public ?string $visitorKey,
        public ?string $channel,
        public ?string $origin,
    ) {}
}
