<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EventEscalationType;
use App\Models\Event;
use App\Models\EventEscalation;
use App\Models\User;
use App\Notifications\EventEscalationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EscalatePendingEvents implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $now = now()->toImmutable();
        $moderators = null;
        $superAdmins = null;

        Event::query()
            ->where('status', 'pending')
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '>', $now);
            })
            ->chunkById(100, function (Collection $events) use ($now, &$moderators, &$superAdmins): void {
                foreach ($events as $event) {
                    $this->processEvent($event, $now, $moderators, $superAdmins);
                }
            });
    }

    /**
     * @param  Collection<int, User>|null  $moderators
     * @param  Collection<int, User>|null  $superAdmins
     */
    private function processEvent(
        Event $event,
        CarbonImmutable $now,
        ?Collection &$moderators,
        ?Collection &$superAdmins,
    ): void {
        if ($event->created_at?->lte($now->subHours(48))) {
            $moderators ??= User::role('moderator')->get();
            $this->escalate(
                $event,
                EventEscalationType::ModeratorSla,
                'Pending moderation reached the 48-hour SLA.',
                '48_hours',
                $moderators,
                $now,
            );
        }

        if (
            $event->created_at?->lte($now->subHours(72))
            && $this->moderatorEscalationReached($event, $now)
        ) {
            $superAdmins ??= User::role('super_admin')->get();
            $this->escalate(
                $event,
                EventEscalationType::SuperAdminSla,
                'Pending moderation reached the 72-hour SLA.',
                '72_hours',
                $superAdmins,
                $now,
            );
        }

        if ($event->starts_at?->gt($now) && $event->starts_at->lte($now->addHours(24)) && $event->starts_at->gt($now->addHours(6))) {
            $moderators ??= User::role('moderator')->get();
            $this->escalate(
                $event,
                EventEscalationType::Imminent,
                'Pending event starts within 24 hours.',
                'urgent',
                $moderators,
                $now,
            );
        }

        if ($event->starts_at?->gt($now) && $event->starts_at->lte($now->addHours(6))) {
            $moderators ??= User::role('moderator')->get();
            $superAdmins ??= User::role('super_admin')->get();
            $this->escalate(
                $event,
                EventEscalationType::Priority,
                'Pending event starts within 6 hours.',
                'priority',
                $moderators->merge($superAdmins),
                $now,
            );
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

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function escalate(
        Event $event,
        EventEscalationType $type,
        string $reason,
        string $notificationType,
        Collection $recipients,
        CarbonImmutable $now,
    ): void {
        $decisionKey = $event->id.':'.$type->value;

        try {
            $escalation = EventEscalation::create([
                'event_id' => $event->id,
                'type' => $type,
                'decision_key' => $decisionKey,
                'reason' => $reason,
            ]);
        } catch (QueryException $exception) {
            if (! EventEscalation::query()->where('decision_key', $decisionKey)->exists()) {
                throw $exception;
            }

            return;
        }

        Log::info('Event escalation recorded.', [
            'event_id' => $event->id,
            'type' => $type->value,
        ]);

        foreach ($recipients as $recipient) {
            $recipient->notify(new EventEscalationNotification($event, $notificationType));
        }

        if ($recipients->isNotEmpty()) {
            $escalation->update(['dispatched_at' => $now]);
        }
    }
}
