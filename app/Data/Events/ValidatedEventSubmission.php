<?php

declare(strict_types=1);

namespace App\Data\Events;

use App\Enums\EventPrayerTime;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use Carbon\Carbon;

final readonly class ValidatedEventSubmission
{
    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $speakerSlugSegments
     */
    public function __construct(
        public array $state,
        public Carbon $startsAt,
        public ?Carbon $endsAt,
        public string $timezone,
        public Institution|Speaker $primaryOrganizer,
        public ?string $targetInstitutionId,
        public ?string $targetVenueId,
        public ?EventPrayerTime $prayerTime,
        public ?string $prayerReference,
        public ?string $prayerOffset,
        public ?string $prayerDisplayText,
        public bool $autoApproved,
        public bool $sessionSubmission,
        public ?User $submitter,
        public ?Event $eventContainer,
        public array $speakerSlugSegments,
    ) {}
}
