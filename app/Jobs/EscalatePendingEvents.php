<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\User;
use App\Notifications\EventEscalationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EscalatePendingEvents implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     *
     * Two escalation dimensions:
     *
     * 1. TIME FROM SUBMISSION (SLA-based):
     *    - Pending > 48 hours: notify moderators
     *    - Pending > 72 hours: notify super admin
     *
     * 2. TIME TO EVENT START (Urgency-based):
     *    - Starts within 24 hours and still pending: notify moderators
     *    - Starts within 6 hours and still pending: mark as priority + urgent notification
     */
    public function handle(): void
    {
        // SLA-based escalations (time from submission)
        $this->escalateToModerators();
        $this->escalateToSuperAdmin();

        // Urgency-based escalations (time to event start)
        $this->notifyUrgentEvents();
        $this->markPriorityEvents();
    }

    /**
     * SLA: Notify moderators for events pending > 48 hours since submission.
     */
    private function escalateToModerators(): void
    {
        $events = Event::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subHours(48))
            ->where(fn ($query) => $this->whereNotEscalated($query))
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $moderators = User::role('moderator')->get();

        foreach ($events as $event) {
            Log::info("SLA Escalation: Event {$event->id} pending > 48 hours - notifying moderators");

            $event->update(['escalated_at' => now()]);

            foreach ($moderators as $moderator) {
                $moderator->notify(new EventEscalationNotification($event, '48_hours'));
            }
        }
    }

    /**
     * SLA: Notify super admin for events pending > 72 hours since submission.
     * Only escalates events that were already escalated to moderators 24+ hours ago.
     */
    private function escalateToSuperAdmin(): void
    {
        $cutoff = now()->subHours(24)->toIso8601String();

        $events = Event::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subHours(72))
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where(function ($metadataQuery) use ($cutoff): void {
                        $metadataQuery
                            ->whereNotNull('metadata->escalated_at')
                            ->where('metadata->escalated_at', '<=', $cutoff);
                    })
                    ->orWhereExists(function ($attributeQuery) use ($cutoff): void {
                        $attributeQuery
                            ->selectRaw('1')
                            ->from('event_attributes')
                            ->whereColumn('event_attributes.event_id', 'events.id')
                            ->where('event_attributes.attribute_key', 'escalated_at')
                            ->whereNotNull('event_attributes.attribute_value')
                            ->where('event_attributes.attribute_value', '!=', '')
                            ->where('event_attributes.attribute_value', '<=', $cutoff);
                    });
            })
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $superAdmins = User::role('super_admin')->get();

        foreach ($events as $event) {
            Log::warning("SLA Escalation: Event {$event->id} pending > 72 hours - notifying super admin");

            foreach ($superAdmins as $admin) {
                $admin->notify(new EventEscalationNotification($event, '72_hours'));
            }

            // Update escalated_at to prevent repeated notifications
            $event->update(['escalated_at' => now()]);
        }
    }

    /**
     * URGENCY: Notify moderators about events starting within 24 hours that are still pending.
     * This catches events that haven't triggered SLA escalation yet but are time-sensitive.
     */
    private function notifyUrgentEvents(): void
    {
        $events = Event::query()
            ->where('status', 'pending')
            ->where('starts_at', '<=', now()->addHours(24))
            ->where('starts_at', '>', now()->addHours(6)) // Not yet priority
            ->where('starts_at', '>', now()) // Not past
            ->where(fn ($query) => $this->whereNotPriority($query))
            ->where(fn ($query) => $this->whereNotEscalated($query))
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $moderators = User::role('moderator')->get();

        foreach ($events as $event) {
            $hoursUntilStart = now()->diffInHours($event->starts_at);
            Log::info("Urgency Alert: Event {$event->id} starts in {$hoursUntilStart} hours - notifying moderators");

            $event->update(['escalated_at' => now()]);

            foreach ($moderators as $moderator) {
                $moderator->notify(new EventEscalationNotification($event, 'urgent'));
            }
        }
    }

    /**
     * URGENCY: Mark events starting within 6 hours as priority.
     * These need immediate attention - event is imminent.
     */
    private function markPriorityEvents(): void
    {
        $events = Event::query()
            ->where('status', 'pending')
            ->where('starts_at', '<=', now()->addHours(6))
            ->where('starts_at', '>', now()) // Not past
            ->where(fn ($query) => $this->whereNotPriority($query))
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $moderators = User::role('moderator')->get();
        $superAdmins = User::role('super_admin')->get();

        foreach ($events as $event) {
            $hoursUntilStart = now()->diffInHours($event->starts_at);
            Log::warning("PRIORITY: Event {$event->id} starts in {$hoursUntilStart} hours - marking as priority");

            $event->update([
                'is_priority' => true,
                'escalated_at' => now(),
            ]);

            // Notify both moderators AND super admins for priority events
            foreach ($moderators as $moderator) {
                $moderator->notify(new EventEscalationNotification($event, 'priority'));
            }

            foreach ($superAdmins as $admin) {
                $admin->notify(new EventEscalationNotification($event, 'priority'));
            }
        }
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function whereNotEscalated($query)
    {
        return $query->where(function ($inner): void {
            $inner
                ->where(function ($metadataQuery): void {
                    $metadataQuery
                        ->whereNull('metadata->escalated_at')
                        ->orWhere('metadata->escalated_at', '')
                        ->orWhere('metadata->escalated_at', 'null');
                })
                ->whereNotExists(function ($attributeQuery): void {
                    $attributeQuery
                        ->selectRaw('1')
                        ->from('event_attributes')
                        ->whereColumn('event_attributes.event_id', 'events.id')
                        ->where('event_attributes.attribute_key', 'escalated_at')
                        ->whereNotNull('event_attributes.attribute_value')
                        ->where('event_attributes.attribute_value', '!=', '');
                });
        });
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function whereNotPriority($query)
    {
        return $query->where(function ($inner): void {
            $inner
                ->where(function ($metadataQuery): void {
                    $metadataQuery
                        ->whereNull('metadata->is_priority')
                        ->orWhere('metadata->is_priority', false)
                        ->orWhere('metadata->is_priority', 0)
                        ->orWhere('metadata->is_priority', '0')
                        ->orWhere('metadata->is_priority', 'false');
                })
                ->whereNotExists(function ($attributeQuery): void {
                    $attributeQuery
                        ->selectRaw('1')
                        ->from('event_attributes')
                        ->whereColumn('event_attributes.event_id', 'events.id')
                        ->where('event_attributes.attribute_key', 'is_priority')
                        ->where('event_attributes.attribute_value', '1');
                });
        });
    }
}
