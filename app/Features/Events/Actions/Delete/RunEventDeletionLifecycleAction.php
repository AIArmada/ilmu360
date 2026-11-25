<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Delete;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Features\Catalog\Actions\DeleteCatalogScopeDependents;
use App\Features\Events\Api\EventDeletion;
use App\Features\Events\Api\EventDeletionScope;
use App\Features\Events\Models\Event;

final readonly class RunEventDeletionLifecycleAction
{
    public function __construct(
        private EventDeletionScope $scope,
        private EventDeletion $deletion,
        private DeleteCatalogScopeDependents $cascade,
    ) {}

    public function handle(Event $event): void
    {
        $eventId = (string) $event->getKey();

        $this->scope->whileDeleting($eventId, function () use ($event, $eventId): void {
            OwnerContext::withOwner(null, function () use ($event, $eventId): void {
                $this->deletion->cleanup($eventId);
                $this->cascade->deleteForScope('event', $eventId, $event->getMorphClass());
            });
        });
    }
}
