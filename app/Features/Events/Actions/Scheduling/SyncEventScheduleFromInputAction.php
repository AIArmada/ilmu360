<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Scheduling;

use AIArmada\Events\Enums\ScheduleKind;
use App\Features\Events\Api\EventScheduleInput;
use App\Features\Events\Support\EventModel;
use App\Shared\Events\Enums\TimingMode;
use Illuminate\Support\Carbon;

/**
 * Resolve an event by identifier and apply a schedule input value.
 *
 * Keeps the contribution and admin transport adapters on one schedule
 * translation instead of duplicating the enum/date mapping.
 */
final readonly class SyncEventScheduleFromInputAction
{
    public function __construct(
        private SyncEventScheduleAction $syncSchedule,
    ) {}

    public function handle(string $eventId, EventScheduleInput $schedule): void
    {
        $this->syncSchedule->execute(
            EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId),
            ScheduleKind::tryFrom($schedule->scheduleKind) ?? ScheduleKind::Single,
            startsAt: $this->parseDate($schedule->startsAt),
            endsAt: $this->parseDate($schedule->endsAt),
            timezone: $schedule->timezone,
            timingMode: $schedule->timingMode !== null ? TimingMode::tryFrom($schedule->timingMode) : null,
            prayerReference: $schedule->prayerReference,
            prayerOffset: $schedule->prayerOffset,
            prayerDisplayText: $schedule->prayerDisplayText,
            prayerSource: $schedule->prayerSource,
            prayerFetchedAt: $schedule->prayerFetchedAt,
            prayerZone: $schedule->prayerZone,
            prayerDate: $schedule->prayerDate,
            prayerLat: $schedule->prayerLat,
            prayerLng: $schedule->prayerLng,
            prayerVenueId: $schedule->prayerVenueId,
            prayerInstitutionId: $schedule->prayerInstitutionId,
            prayerCountry: $schedule->prayerCountry,
        );
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
