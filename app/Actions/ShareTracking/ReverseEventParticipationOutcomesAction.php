<?php

declare(strict_types=1);

namespace App\Actions\ShareTracking;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Actions\Events\Commerce\ReverseTicketAffiliateCommissionAction;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Reverse participation share outcomes when an event, occurrence, or
 * session is cancelled.
 *
 * Cancellation preserves history — the source rows and their frozen
 * attributed-or-unattributed receipts stay — but currently-valid impact
 * and pending effects must not survive: registrations and check-ins for
 * a cancelled scope no longer qualify. Scoped cancellations reverse only
 * their actual registrations/check-ins, never unrelated event
 * contributions such as submissions. Callers run this inside the same
 * transaction as the canonical source transition.
 */
final readonly class ReverseEventParticipationOutcomesAction
{
    use AsAction;

    public function __construct(
        private ReverseShareOutcomeAction $reverseShareOutcome,
        private ReverseTicketAffiliateCommissionAction $reverseTicketAffiliateCommission,
    ) {}

    public function forEvent(Event|Model $event, ?User $actor, string $reason = 'event_cancelled'): void
    {
        $this->reverseRegistrations(Registration::query()->where('event_id', $event->getKey()), $reason, $actor);

        EventCheckin::query()
            ->where('event_id', $event->getKey())
            ->lockForUpdate()
            ->pluck('id')
            ->each(fn (mixed $checkinId): mixed => $this->reverseShareOutcome->handle(
                ShareOutcomeBusinessKey::eventCheckin((string) $checkinId),
                $reason,
                $actor,
                request(),
            ));
    }

    public function forOccurrence(EventOccurrence|Model $occurrence, ?User $actor, string $reason = 'occurrence_cancelled'): void
    {
        $this->reverseRegistrations(Registration::query()->where('event_occurrence_id', $occurrence->getKey()), $reason, $actor);

        EventCheckin::query()
            ->where('event_occurrence_id', $occurrence->getKey())
            ->lockForUpdate()
            ->pluck('id')
            ->each(fn (mixed $checkinId): mixed => $this->reverseShareOutcome->handle(
                ShareOutcomeBusinessKey::eventCheckin((string) $checkinId),
                $reason,
                $actor,
                request(),
            ));
    }

    public function forSession(EventSession|Model $session, ?User $actor, string $reason = 'session_cancelled'): void
    {
        $this->reverseRegistrations(Registration::query()->where('event_session_id', $session->getKey()), $reason, $actor);

        EventCheckin::query()
            ->where('event_session_id', $session->getKey())
            ->lockForUpdate()
            ->pluck('id')
            ->each(fn (mixed $checkinId): mixed => $this->reverseShareOutcome->handle(
                ShareOutcomeBusinessKey::eventCheckin((string) $checkinId),
                $reason,
                $actor,
                request(),
            ));
    }

    /**
     * @param  Builder<Registration>  $query
     */
    private function reverseRegistrations(Builder $query, string $reason, ?User $actor): void
    {
        $query->lockForUpdate()->get()->each(function (Registration $registration) use ($reason, $actor): void {
            $this->reverseTicketAffiliateCommission->handle($registration, $reason);
            $this->reverseShareOutcome->handle(
                ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey()),
                $reason,
                $actor,
                request(),
            );
        });
    }
}
