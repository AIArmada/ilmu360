<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\EntryPoints\Listeners;

use AIArmada\Events\Events\EventCancelled;
use AIArmada\Events\Events\EventOccurrenceCancelled;
use AIArmada\Events\Events\EventSessionCancelled;
use App\Features\ShareTracking\Api\ParticipationOutcomeReversals;

/**
 * Reverse participation share outcomes when a schedule scope is cancelled
 * through the package lifecycle workflow.
 *
 * The package fires these events inside the source status transaction, so
 * this synchronous listener joins the same transaction: the reversals
 * commit or roll back with the source change. Event-wide cancellations
 * reverse the event's registrations/check-ins; occurrence/session
 * cancellations reverse only their actual scoped rows, never unrelated
 * event contributions.
 */
final readonly class ReverseScheduleCancellationShareOutcome
{
    public function __construct(
        private ParticipationOutcomeReversals $reverseParticipation,
    ) {}

    public function handle(EventCancelled|EventOccurrenceCancelled|EventSessionCancelled $event): void
    {
        match (true) {
            $event instanceof EventOccurrenceCancelled => $this->reverseParticipation->reverse(
                'occurrence', (string) $event->occurrence->getKey(), null, 'occurrence_cancelled',
            ),
            $event instanceof EventSessionCancelled => $this->reverseParticipation->reverse(
                'session', (string) $event->session->getKey(), null, 'session_cancelled',
            ),
            default => $this->reverseParticipation->reverse(
                'event', (string) $event->target->getKey(), null, 'event_cancelled',
            ),
        };
    }
}
