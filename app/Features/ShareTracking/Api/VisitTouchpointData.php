<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Scalar visit facts prepared by the share-tracking orchestration boundary.
 */
final readonly class VisitTouchpointData
{
    public function __construct(
        public string $attributionId,
        public string $affiliateId,
        public string $affiliateCode,
        public ?string $source,
        public ?string $medium,
        public ?string $campaign,
        public ?string $term,
        public ?string $content,
        public ?string $ipAddress,
        public string $visitKind,
        public ?string $linkId,
        public ?string $visitorKey,
        public ?string $channel,
        public ?string $origin,
        public string $url,
        public ?string $subjectType,
        public ?string $subjectKey,
        public ?string $subjectId,
    ) {}
}
