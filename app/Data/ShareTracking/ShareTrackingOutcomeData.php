<?php

declare(strict_types=1);

namespace App\Data\ShareTracking;

use Carbon\CarbonInterface;

final readonly class ShareTrackingOutcomeData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $backend,
        public string $linkId,
        public ?string $attributionId,
        public ?string $sharerUserId,
        public ?string $actorUserId,
        public string $outcomeType,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $subjectKey,
        public string $outcomeKey,
        public ?string $linkTitleSnapshot,
        public ?CarbonInterface $occurredAt,
        public array $metadata = [],
    ) {}

    public function __get(string $name): mixed
    {
        return match ($name) {
            'link_id' => $this->linkId,
            'attribution_id' => $this->attributionId,
            'sharer_user_id' => $this->sharerUserId,
            'actor_user_id' => $this->actorUserId,
            'outcome_type' => $this->outcomeType,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_key' => $this->subjectKey,
            'outcome_key' => $this->outcomeKey,
            'link_title_snapshot' => $this->linkTitleSnapshot,
            'occurred_at' => $this->occurredAt,
            default => null,
        };
    }
}
