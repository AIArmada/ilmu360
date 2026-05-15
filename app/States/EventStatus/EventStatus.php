<?php

declare(strict_types=1);

namespace App\States\EventStatus;

use App\Models\Event;
use App\Support\State\StateMetadata;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * @extends State<Event>
 */
abstract class EventStatus extends State
{
    use StateMetadata;

    #[\Override]
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Draft::class)
            // Submission flow
            ->allowTransition(Draft::class, Pending::class, Transitions\SubmitForModeration::class)
            ->allowTransition(NeedsChanges::class, Pending::class, Transitions\SubmitForModeration::class)
            // Moderation decisions (from Pending)
            ->allowTransition(Pending::class, Approved::class, Transitions\ApproveEvent::class)
            ->allowTransition(Pending::class, NeedsChanges::class, Transitions\RequestChanges::class)
            ->allowTransition(Pending::class, Rejected::class, Transitions\RejectEvent::class)
            ->allowTransition(Pending::class, Cancelled::class, Transitions\CancelEvent::class)
            // Cancellation flow
            ->allowTransition(Approved::class, Cancelled::class, Transitions\CancelEvent::class)
            ->allowTransition(Cancelled::class, Pending::class, Transitions\RemoderateEvent::class)
            // Re-moderation (Approved back to Pending)
            ->allowTransition(Approved::class, Pending::class, Transitions\RemoderateEvent::class)
            // Reconsider rejected events (back to Pending for review)
            ->allowTransition(Rejected::class, Pending::class, Transitions\ReconsiderEvent::class)
            // Revert to draft (from any terminal state)
            ->allowTransition(Rejected::class, Draft::class, Transitions\RevertToDraft::class)
            ->allowTransition(NeedsChanges::class, Draft::class, Transitions\RevertToDraft::class)
            ->allowTransition(Cancelled::class, Draft::class, Transitions\RevertToDraft::class);
    }
}
