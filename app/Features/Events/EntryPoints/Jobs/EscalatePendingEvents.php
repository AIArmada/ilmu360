<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Jobs;

use AIArmada\Events\Enums\EventEscalationType;
use AIArmada\Events\Models\EventEscalation;
use App\Features\Events\Actions\Escalations\RecordPendingEventEscalationAction;
use App\Features\Events\Contracts\EventEscalationTransport;
use App\Features\Events\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EscalatePendingEvents implements ShouldQueue
{
    use Queueable;

    public function handle(EventEscalationTransport $transport, RecordPendingEventEscalationAction $records): void
    {
        $now = now()->toImmutable();
        $moderators = null;
        $superAdmins = null;

        Event::query()
            ->where('status', 'pending')
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '>', $now);
            })
            ->chunkById(100, function (Collection $events) use ($now, &$moderators, &$superAdmins, $transport, $records): void {
                foreach ($events as $event) {
                    $this->processEvent($event, $now, $moderators, $superAdmins, $transport, $records);
                }
            });
    }

    /**
     * @param  Collection<int, Authenticatable>|null  $moderators
     * @param  Collection<int, Authenticatable>|null  $superAdmins
     */
    private function processEvent(
        Event $event,
        CarbonImmutable $now,
        ?Collection &$moderators,
        ?Collection &$superAdmins,
        EventEscalationTransport $transport,
        RecordPendingEventEscalationAction $records,
    ): void {
        if ($event->created_at?->lte($now->subHours(48))) {
            $moderators ??= $transport->forRole('moderator');
            $this->escalate($event, EventEscalationType::ModeratorSla, 'Pending moderation reached the 48-hour SLA.', '48_hours', $moderators, $now, $transport, $records);
        }

        if (
            $event->created_at?->lte($now->subHours(72))
            && $this->moderatorEscalationReached($event, $now)
        ) {
            $superAdmins ??= $transport->forRole('super_admin');
            $this->escalate($event, EventEscalationType::SuperAdminSla, 'Pending moderation reached the 72-hour SLA.', '72_hours', $superAdmins, $now, $transport, $records);
        }

        if ($event->starts_at?->gt($now) && $event->starts_at->lte($now->addHours(24)) && $event->starts_at->gt($now->addHours(6))) {
            $moderators ??= $transport->forRole('moderator');
            $this->escalate($event, EventEscalationType::Imminent, 'Pending event starts within 24 hours.', 'urgent', $moderators, $now, $transport, $records);
        }

        if ($event->starts_at?->gt($now) && $event->starts_at->lte($now->addHours(6))) {
            $moderators ??= $transport->forRole('moderator');
            $superAdmins ??= $transport->forRole('super_admin');
            $this->escalate($event, EventEscalationType::Priority, 'Pending event starts within 6 hours.', 'priority', $moderators->merge($superAdmins), $now, $transport, $records);
        }
    }

    private function moderatorEscalationReached(Event $event, CarbonImmutable $now): bool
    {
        return $event->escalations()
            ->where('type', EventEscalationType::ModeratorSla->value)
            ->whereNull('resolved_at')
            ->where('created_at', '<=', $now->subHours(24))
            ->exists();
    }

    /** @param Collection<int, Authenticatable> $recipients */
    private function escalate(
        Event $event,
        EventEscalationType $type,
        string $reason,
        string $notificationType,
        Collection $recipients,
        CarbonImmutable $now,
        EventEscalationTransport $transport,
        RecordPendingEventEscalationAction $records,
    ): void {
        $escalation = $records->record($event, $type, $reason, $now);

        if (! $escalation instanceof EventEscalation) {
            return;
        }

        Log::info('Event escalation recorded.', [
            'event_id' => $event->id,
            'type' => $type->value,
        ]);

        $transport->notify((string) $event->getKey(), $notificationType, $recipients);

        if ($recipients->isNotEmpty()) {
            $records->markDispatched($escalation, $now);
        }
    }
}
