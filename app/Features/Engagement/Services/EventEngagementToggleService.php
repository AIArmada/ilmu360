<?php

declare(strict_types=1);

namespace App\Features\Engagement\Services;

use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventEngagementToggles;
use App\Features\Engagement\Api\EventGoingCommands;
use App\Features\Engagement\Api\EventSaveCommands;
use App\Features\Events\Api\EventEngagementEligibility;
use App\Models\User;
use App\Shared\Auth\IntendedRedirect;

/**
 * Owner implementation of the event going/save toggles.
 */
final readonly class EventEngagementToggleService implements EventEngagementToggles
{
    public function __construct(
        private EventEngagement $engagement,
        private EventEngagementEligibility $eligibility,
        private EventGoingCommands $goingCommands,
        private EventSaveCommands $saveCommands,
    ) {}

    public function toggleGoing(string $eventId, string $returnUrl): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return IntendedRedirect::loginUrl($returnUrl);
        }

        if (! $this->eligibility->isGoingEligible($eventId)) {
            return null;
        }

        $accountId = (string) $user->getKey();

        if ($this->engagement->isGoingToEvent($accountId, $eventId)) {
            $this->goingCommands->remove($eventId, $accountId);

            return null;
        }

        $this->goingCommands->mark($eventId, $accountId);

        return null;
    }

    public function toggleSave(string $eventId, string $returnUrl): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return IntendedRedirect::loginUrl($returnUrl);
        }

        if (! $this->eligibility->isSaveEligible($eventId)) {
            return null;
        }

        $accountId = (string) $user->getKey();

        if ($this->engagement->hasSavedEvent($accountId, $eventId)) {
            $this->saveCommands->unsave($eventId, $accountId);

            return null;
        }

        $this->saveCommands->save($eventId, $accountId);

        return null;
    }
}
