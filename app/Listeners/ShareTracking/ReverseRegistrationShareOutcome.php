<?php

declare(strict_types=1);

namespace App\Listeners\ShareTracking;

use AIArmada\Events\Events\EventRegistrationCancelled;
use AIArmada\Events\Events\EventRegistrationRejected;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Models\EventCheckin;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;

/**
 * Reverse the registration share outcome when its source is cancelled
 * or rejected.
 *
 * The package fires these events inside the source status transaction,
 * so this synchronous listener joins the same transaction: the reversal
 * commits or rolls back with the source change. Registrations that
 * never credited (paid admissions, unattributed receipts) reverse to a
 * harmless no-op, and paid/held financial records stay guarded inside
 * the reversal helper.
 */
final readonly class ReverseRegistrationShareOutcome
{
    public function __construct(
        private ReverseShareOutcomeAction $reverseShareOutcome,
    ) {}

    public function handle(EventRegistrationCancelled|EventRegistrationRejected $event): void
    {
        $actor = $event->registration->registrant;
        $reason = $event instanceof EventRegistrationRejected ? 'registration_rejected' : 'registration_cancelled';

        $this->reverseShareOutcome->handle(
            ShareOutcomeBusinessKey::eventRegistration((string) $event->registration->getKey()),
            $reason,
            $actor instanceof User ? $actor : null,
            request(),
        );

        EventCheckin::query()
            ->where('event_registration_id', $event->registration->getKey())
            ->lockForUpdate()
            ->pluck('id')
            ->each(fn (mixed $checkinId): mixed => $this->reverseShareOutcome->handle(
                ShareOutcomeBusinessKey::eventCheckin((string) $checkinId),
                $reason,
                $actor instanceof User ? $actor : null,
                request(),
            ));
    }
}
