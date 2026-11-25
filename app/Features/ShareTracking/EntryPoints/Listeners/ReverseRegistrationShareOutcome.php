<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\EntryPoints\Listeners;

use AIArmada\Events\Events\EventRegistrationCancelled;
use AIArmada\Events\Events\EventRegistrationRefunded;
use AIArmada\Events\Events\EventRegistrationRejected;
use App\Features\Events\Api\TicketAffiliateReversals;
use App\Features\Registration\Api\RegistrationLookup;
use App\Features\ShareTracking\Api\ShareOutcomeBusinessKey;
use App\Features\ShareTracking\Api\ShareOutcomeReversals;
use App\Models\User;
use App\Platform\Models\MorphModelResolver;

/**
 * Reverse ticket commission and share outcomes when their source is cancelled,
 * rejected, or refunded.
 *
 * The package fires these events inside the source status transaction,
 * so this synchronous listener joins the same transaction: the reversal
 * commits or rolls back with the source change. Registrations that
 * never credited (paid admissions, unattributed receipts) reverse to a
 * harmless no-op, and paid/held financial records stay guarded inside
 * the reversal helper. The event payload carries scalar source identity so
 * the listener never hydrates package models.
 */
final readonly class ReverseRegistrationShareOutcome
{
    public function __construct(
        private ShareOutcomeReversals $reverseShareOutcome,
        private TicketAffiliateReversals $reverseTicketAffiliateCommission,
        private RegistrationLookup $registrationLookup,
    ) {}

    public function handle(EventRegistrationCancelled|EventRegistrationRejected|EventRegistrationRefunded $event): void
    {
        $reason = match (true) {
            $event instanceof EventRegistrationRejected => 'registration_rejected',
            $event instanceof EventRegistrationRefunded => 'registration_refunded',
            default => 'registration_cancelled',
        };

        $actorId = $this->resolveActorId($event->registrantType, $event->registrantId);

        $this->reverseTicketAffiliateCommission->reverse($event->registrationId, $reason);

        $this->reverseShareOutcome->reverse(
            ShareOutcomeBusinessKey::eventRegistration($event->registrationId),
            $reason,
            $actorId,
        );

        foreach ($this->registrationLookup->lockedCheckInIdsForRegistration($event->registrationId) as $checkinId) {
            $this->reverseShareOutcome->reverse(
                ShareOutcomeBusinessKey::eventCheckin($checkinId),
                $reason,
                $actorId,
            );
        }
    }

    private function resolveActorId(?string $registrantType, ?string $registrantId): ?string
    {
        if ($registrantType === null || $registrantId === null) {
            return null;
        }

        $resolved = MorphModelResolver::tryResolve($registrantType);

        return $resolved !== null && is_a($resolved, User::class, true) ? $registrantId : null;
    }
}
