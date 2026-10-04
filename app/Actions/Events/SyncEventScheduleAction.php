<?php

namespace App\Actions\Events;

use AIArmada\Events\Actions\SyncPrimaryEventOccurrenceAction;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTimeExpression;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Services\Prayer\HardcodedPrayerFallback;
use App\Services\PrayerTimeExpressionResolver;
use App\Support\Events\AdminEventTimeMapper;
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
        ?string $prayerSource = null,
        ?string $prayerFetchedAt = null,
        ?string $prayerZone = null,
        ?string $prayerDate = null,
        ?float $prayerLat = null,
        ?float $prayerLng = null,
        ?string $prayerVenueId = null,
        ?string $prayerInstitutionId = null,
        ?string $prayerCountry = null,
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
        $occurrence = DB::transaction(function () use ($event, $attributes, $startsAt, $timingMode, $prayerOffset, $prayerReference, $prayerDisplayText, $prayerSource, $prayerFetchedAt, $prayerZone, $prayerDate, $prayerLat, $prayerLng, $prayerVenueId, $prayerInstitutionId, $prayerCountry) {
            $synced = $this->syncPrimaryOccurrence->handle($event, $attributes);

            if ($timingMode === TimingMode::PrayerRelative) {
                $offsetMinutes = $prayerOffset ?? 5;

                $expression = EventTimeExpression::updateOrCreate(
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

                // Fetch provenance for the persisted clock: which source and
                // zone produced it, defaulting to the hardcoded floor. The
                // prayer anchor date travels too: offsets can roll the start
                // past midnight, and reopening the form must re-resolve the
                // original prayer day — not the rolled start day. Resolver
                // coordinates and submission targets travel as well so
                // re-resolution reproduces submission's cache inputs exactly
                // (representative coords included) instead of re-deriving
                // them from possibly coordinate-less persisted locations.
                // The submission country travels too: the calculation
                // identity must stay pinned to what submission resolved,
                // never re-read from an address edited since.
                $metadata = $expression->metadata ?? [];
                $metadata['prayer'] = array_filter([
                    'source' => $prayerSource ?? HardcodedPrayerFallback::SOURCE,
                    'fetched_at' => $prayerFetchedAt ?? now()->toIso8601String(),
                    'country' => $prayerCountry,
                    'zone' => $prayerZone,
                    'prayer_date' => AdminEventTimeMapper::normalizePrayerDateString($prayerDate),
                    'lat' => $prayerLat,
                    'lng' => $prayerLng,
                    'venue_id' => $prayerVenueId,
                    'institution_id' => $prayerInstitutionId,
                ], static fn (mixed $value): bool => $value !== null);

                $expression->forceFill([
                    'metadata' => $metadata,
                    'resolved_at' => now(),
                ]);

                if ($startsAt instanceof CarbonInterface) {
                    $expression->forceFill(['resolved_starts_at' => $startsAt]);
                }

                $expression->save();
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
