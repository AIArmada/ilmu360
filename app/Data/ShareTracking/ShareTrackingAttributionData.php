<?php

declare(strict_types=1);

namespace App\Data\ShareTracking;

use Carbon\CarbonInterface;

final readonly class ShareTrackingAttributionData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $backend,
        public string $linkId,
        public ?string $visitorKey,
        public ?string $cookieValue,
        public ?string $landingUrl,
        public ?string $shareProvider,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $subjectKey,
        public ?string $titleSnapshot,
        public ?CarbonInterface $firstSeenAt,
        public ?CarbonInterface $lastSeenAt,
        public ?CarbonInterface $expiresAt,
        public array $metadata = [],
    ) {}

    public function __get(string $name): mixed
    {
        return match ($name) {
            'link_id' => $this->linkId,
            'visitor_key' => $this->visitorKey,
            'cookie_value' => $this->cookieValue,
            'landing_url' => $this->landingUrl,
            'share_provider' => $this->shareProvider,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_key' => $this->subjectKey,
            'title_snapshot' => $this->titleSnapshot,
            'first_seen_at' => $this->firstSeenAt,
            'last_seen_at' => $this->lastSeenAt,
            'expires_at' => $this->expiresAt,
            default => null,
        };
    }
}
