<?php

declare(strict_types=1);

namespace App\Data\ShareTracking;

use Carbon\CarbonInterface;

final readonly class ShareTrackingVisitData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $backend,
        public string $linkId,
        public ?string $attributionId,
        public ?string $visitorKey,
        public string $visitedUrl,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $subjectKey,
        public string $visitKind,
        public ?CarbonInterface $occurredAt,
        public array $metadata = [],
    ) {}

    public function __get(string $name): mixed
    {
        return match ($name) {
            'link_id' => $this->linkId,
            'attribution_id' => $this->attributionId,
            'visitor_key' => $this->visitorKey,
            'visited_url' => $this->visitedUrl,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_key' => $this->subjectKey,
            'visit_kind' => $this->visitKind,
            'occurred_at' => $this->occurredAt,
            default => null,
        };
    }
}
