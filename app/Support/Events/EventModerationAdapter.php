<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Features\Events\Api\EventModeration;
use App\Models\Event;
use App\Models\User;
use App\Services\ModerationService;
use App\Support\Moderation\EventModerationWorkflow;

/**
 * Root adapter for the Events moderation boundary.
 *
 * Resolves scalar identifiers back to the conventional Event/User models and
 * delegates every transition to ModerationService so state transitions,
 * notifications, share reversals and signals remain untouched. Event lookup
 * keeps the model's owner scope, matching the admin resource registry and
 * moderation queue callers this adapter replaces.
 */
final readonly class EventModerationAdapter implements EventModeration
{
    public function __construct(
        private ModerationService $moderation,
    ) {}

    public function submitForModeration(string $eventId): void
    {
        $this->moderation->submitForModeration($this->event($eventId));
    }

    public function approve(string $eventId, ?string $actorId, ?string $note = null): void
    {
        $this->moderation->approve($this->event($eventId), $this->actor($actorId), $note);
    }

    public function requestChanges(string $eventId, string $actorId, string $reasonCode, ?string $note = null): void
    {
        $this->moderation->requestChanges(
            $this->event($eventId),
            $this->requiredActor($actorId),
            $reasonCode,
            $note,
        );
    }

    public function reject(string $eventId, string $actorId, string $reasonCode, ?string $note = null): void
    {
        $this->moderation->reject(
            $this->event($eventId),
            $this->requiredActor($actorId),
            $reasonCode,
            $note,
        );
    }

    public function cancel(string $eventId, ?string $actorId, ?string $note = null): void
    {
        $this->moderation->cancel($this->event($eventId), $this->actor($actorId), $note);
    }

    public function reconsider(string $eventId, ?string $actorId, ?string $note = null): void
    {
        $this->moderation->reconsider($this->event($eventId), $this->actor($actorId), $note);
    }

    public function revertToDraft(string $eventId, ?string $actorId, ?string $note = null): void
    {
        $this->moderation->revertToDraft($this->event($eventId), $this->actor($actorId), $note);
    }

    public function remoderate(string $eventId, ?string $actorId, ?string $note = null, ?string $reasonCode = null): void
    {
        $this->moderation->remoderate(
            $this->event($eventId),
            $this->actor($actorId),
            $note,
            $reasonCode,
        );
    }

    public function handleSensitiveChange(string $eventId, array $changes): void
    {
        $this->moderation->handleSensitiveChange($this->event($eventId), $changes);
    }

    public function availableActions(string $eventId): array
    {
        return EventModerationWorkflow::availableActions($this->event($eventId));
    }

    public function reasonOptions(): array
    {
        return EventModerationWorkflow::reasonOptions();
    }

    public function actionKeys(): array
    {
        return EventModerationWorkflow::allActionKeys();
    }

    private function event(string $eventId): Event
    {
        /** @var Event $event */
        $event = Event::query()->findOrFail($eventId);

        return $event;
    }

    private function actor(?string $actorId): ?User
    {
        if ($actorId === null || $actorId === '') {
            return null;
        }

        /** @var User|null $actor */
        $actor = User::query()->find($actorId);

        return $actor;
    }

    private function requiredActor(string $actorId): User
    {
        /** @var User $actor */
        $actor = User::query()->findOrFail($actorId);

        return $actor;
    }
}
