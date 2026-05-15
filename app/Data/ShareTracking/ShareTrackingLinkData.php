<?php

declare(strict_types=1);

namespace App\Data\ShareTracking;

use Carbon\CarbonInterface;

/**
 * @property-read string $destination_url
 * @property-read string $canonical_url
 * @property-read string $title_snapshot
 * @property-read ?CarbonInterface $last_shared_at
 * @property-read int $outbound_shares
 * @property-read int $visits_count
 * @property-read int $outcomes_count
 * @property-read int $signups_count
 * @property-read int $event_registrations_count
 * @property-read int $event_checkins_count
 * @property-read int $event_submissions_count
 * @property-read ?CarbonInterface $latest_visit_at
 * @property-read ?CarbonInterface $latest_outcome_at
 */
final readonly class ShareTrackingLinkData
{
    public function __construct(
        public string $id,
        public string $backend,
        public string $subjectType,
        public ?string $subjectId,
        public string $subjectKey,
        public string $destinationUrl,
        public string $canonicalUrl,
        public string $titleSnapshot,
        public ?CarbonInterface $lastSharedAt,
        public int $outboundShares = 0,
        public int $visitsCount = 0,
        public int $outcomesCount = 0,
        public int $signupsCount = 0,
        public int $eventRegistrationsCount = 0,
        public int $eventCheckinsCount = 0,
        public int $eventSubmissionsCount = 0,
        public ?CarbonInterface $latestVisitAt = null,
        public ?CarbonInterface $latestOutcomeAt = null,
    ) {}

    public function latestActivityAt(): ?CarbonInterface
    {
        return collect([
            $this->lastSharedAt,
            $this->latestVisitAt,
            $this->latestOutcomeAt,
        ])->filter()->sortByDesc(fn (CarbonInterface $value): int => $value->getTimestamp())->first();
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_key' => $this->subjectKey,
            'destination_url' => $this->destinationUrl,
            'canonical_url' => $this->canonicalUrl,
            'title_snapshot' => $this->titleSnapshot,
            'last_shared_at' => $this->lastSharedAt,
            'outbound_shares' => $this->outboundShares,
            'visits_count' => $this->visitsCount,
            'outcomes_count' => $this->outcomesCount,
            'signups_count' => $this->signupsCount,
            'event_registrations_count' => $this->eventRegistrationsCount,
            'event_checkins_count' => $this->eventCheckinsCount,
            'event_submissions_count' => $this->eventSubmissionsCount,
            'latest_visit_at' => $this->latestVisitAt,
            'latest_outcome_at' => $this->latestOutcomeAt,
            default => null,
        };
    }
}
