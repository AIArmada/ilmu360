<?php

namespace App\Actions\Events;

use AIArmada\Events\Contracts\EventLifecycleWorkflow;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTimeExpression;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Services\PrayerTimeExpressionResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final readonly class SyncEventScheduleAction
{
    public function __construct(
        private EventLifecycleWorkflow $lifecycleWorkflow,
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
        $event->schedule_kind = $scheduleKind;
        $event->save();

        $timezone ??= $event->timezone ?? config('app.timezone', 'UTC');

        $event->unsetRelation('primaryOccurrence');
        $occurrence = $event->primaryOccurrence;

        if ($occurrence && $startsAt) {
            $currentStatus = (string) $occurrence->status;

            if ($endsAt && in_array($currentStatus, ['published', 'postponed'], true)) {
                $this->lifecycleWorkflow->reschedule($occurrence, $startsAt, $endsAt, [
                    'timezone' => $timezone,
                ]);
            } else {
                if ($startsAt instanceof CarbonImmutable) {
                    $startsAt = Carbon::instance($startsAt);
                }
                if ($endsAt instanceof CarbonImmutable) {
                    $endsAt = Carbon::instance($endsAt);
                }
                $occurrence->fill([
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'timezone' => $timezone,
                ]);
                $occurrence->save();
            }
        } elseif ($startsAt instanceof CarbonInterface) {
            $occurrence = EventOccurrence::query()->create([
                'event_id' => $event->id,
                'title' => $event->title,
                'slug' => $event->slug,
                'starts_at' => $startsAt instanceof CarbonImmutable ? Carbon::instance($startsAt) : $startsAt,
                'ends_at' => $endsAt instanceof CarbonImmutable ? Carbon::instance($endsAt) : $endsAt,
                'timezone' => $timezone,
                'status' => EventOccurrence::SCHEDULED,
                'visibility' => $event->visibility?->value ?? 'public',
                'delivery_mode' => $event->delivery_mode ?? 'physical',
            ]);
            $event->setRelation('primaryOccurrence', $occurrence);
        }

        if ($timingMode === TimingMode::PrayerRelative) {
            $offsetMinutes = $prayerOffset ?? 5;

            EventTimeExpression::updateOrCreate(
                [
                    'event_id' => $event->id,
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
            $event->timeExpressions()->where('anchor_type', 'prayer')->delete();
        }

    }
}
