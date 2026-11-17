<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\Reversals;

use App\Features\Events\Api\TicketAffiliateReversals;
use App\Features\Registration\Api\ParticipationSourceIds;
use App\Features\ShareTracking\Api\ParticipationOutcomeReversals;
use App\Features\ShareTracking\Api\ShareOutcomeReversals;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;

/**
 * Cancel participation effects without deleting the historical source facts.
 * Preserves lock order: registration rows, then check-in rows.
 */
final readonly class ReverseEventParticipationOutcomesAction implements ParticipationOutcomeReversals
{
    public function __construct(
        private ParticipationSourceIds $sources,
        private ShareOutcomeReversals $outcomes,
        private TicketAffiliateReversals $affiliateReversals,
    ) {}

    public function reverse(string $scope, string $scopeId, ?string $actorId, string $reason): void
    {
        // Source IDs are selected with FOR UPDATE by Registration while the
        // invoking cancellation transaction is still open.
        foreach ($this->sources->registrationIds($scope, $scopeId) as $registrationId) {
            $this->affiliateReversals->reverse($registrationId, $reason);
            $this->outcomes->reverse(
                ShareOutcomeBusinessKey::eventRegistration($registrationId),
                $reason,
                $actorId,
            );
        }

        foreach ($this->sources->checkinIds($scope, $scopeId) as $checkinId) {
            $this->outcomes->reverse(
                ShareOutcomeBusinessKey::eventCheckin($checkinId),
                $reason,
                $actorId,
            );
        }
    }
}
