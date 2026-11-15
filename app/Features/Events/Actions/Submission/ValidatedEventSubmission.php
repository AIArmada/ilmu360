<?php

namespace App\Features\Events\Actions\Submission;

use AIArmada\Events\Models\Event as PackageEvent;
use App\Shared\Prayer\EventPrayerTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

final readonly class ValidatedEventSubmission
{
    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $personSlugSegments
     */
    public function __construct(
        public array $state,
        public Carbon $startsAt,
        public ?Carbon $endsAt,
        public string $timezone,
        public Model $primaryOrganizer,
        public ?string $targetInstitutionId,
        public ?string $targetVenueId,
        public ?EventPrayerTime $prayerTime,
        public ?string $prayerReference,
        public ?int $prayerOffset,
        public ?string $prayerDisplayText,
        public bool $autoApproved,
        public bool $sessionSubmission,
        public ?Model $submitter,
        public ?PackageEvent $eventContainer,
        public array $personSlugSegments,
        public ?string $occurrenceId = null,
        public string $validationKeyPrefix = '',
        public ?string $prayerSource = null,
        public ?string $prayerFetchedAt = null,
        public ?string $prayerZone = null,
        public ?float $prayerLat = null,
        public ?float $prayerLng = null,
        public ?string $prayerCountry = null,
    ) {}
}
