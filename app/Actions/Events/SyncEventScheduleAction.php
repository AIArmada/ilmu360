<?php

namespace App\Actions\Events;

use AIArmada\Events\Actions\SyncPrimaryEventOccurrenceAction;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTimeExpression;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Services\PrayerTimeExpressionResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Prayer-expression adapter over the generic primary-occurrence writer.
 *
 * Occurrence persistence, lifecycle transitions, and owner guards live in
 * the events package; this action only maps the application prayer
 * TimingMode onto the event-level time expression.
 */
final readonly class SyncEventScheduleAction
{
    public function __construct(
        private SyncPrimaryEventOccurrenceAction $syncPrimaryOccurrence,
    ) {}

    public function execute(
        Event $event,
        ScheduleKind $scheduleKind,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        ?string $timezone = null,
        ?TimingMode $timingMode = null,
        ?string $prayerReference = null,
        ?int $prayerOffset = null,
        ?string $prayerDisplayText = null,
    ): void {
        $attributes = ['schedule_kind' => $scheduleKind];

        if ($startsAt instanceof CarbonInterface) {
            // An explicit null end keeps the occurrence open-ended; it must not
            // fall back to the generic default duration.
            $attributes['starts_at'] = $startsAt;
            $attributes['ends_at'] = $endsAt;

            if ($timezone !== null) {
                $attributes['timezone'] = $timezone;
            }
        }

        // The package writer re-resolves its own event instance inside its own
        // transaction; the prayer expression must commit or roll back with the
        // occurrence even for standalone callers, so orchestrate atomically.
        $occurrence = DB::transaction(function () use ($event, $attributes, $timingMode, $prayerOffset, $prayerReference, $prayerDisplayText) {
            $synced = $this->syncPrimaryOccurrence->handle($event, $attributes);

            if ($timingMode === TimingMode::PrayerRelative) {
                $offsetMinutes = $prayerOffset ?? 5;

                EventTimeExpression::updateOrCreate(
                    [
                        'event_id' => $event->getKey(),
                        'event_occurrence_id' => null,
                        'event_session_id' => null,
                        'anchor_type' => 'prayer',
                    ],
                    [
                        'time_mode' => 'prayer_relative',
                        'anchor_type' => 'prayer',
                        'anchor_code' => $prayerReference,
                        'relation' => $offsetMinutes < 0 ? 'before' : 'after',
                        'offset_minutes' => abs($offsetMinutes),
                        'display_label' => $prayerDisplayText,
                        'resolver_class' => PrayerTimeExpressionResolver::class,
                    ],
                );
            } else {
                EventTimeExpression::query()
                    ->where('event_id', $event->getKey())
                    ->whereNull('event_occurrence_id')
                    ->whereNull('event_session_id')
                    ->where('anchor_type', 'prayer')
                    ->delete();
            }

            return $synced;
        });

        // The package mutated a different instance; refresh the caller's model
        // so schedule_kind and primaryOccurrence never stay stale.
        $event->schedule_kind = $scheduleKind;
        $event->unsetRelation('primaryOccurrence');

        if ($occurrence instanceof EventOccurrence) {
            $event->setRelation('primaryOccurrence', $occurrence);
        }
    }
}
