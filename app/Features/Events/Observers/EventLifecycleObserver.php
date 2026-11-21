<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use App\Features\Events\Actions\Authoring\SyncPendingEventProjectionsAction;
use App\Features\Events\Actions\Delete\RunEventDeletionLifecycleAction;
use App\Features\Events\Models\Event;

final readonly class EventLifecycleObserver
{
    public function __construct(
        private SyncPendingEventProjectionsAction $projections,
        private RunEventDeletionLifecycleAction $deletion,
    ) {}

    public function saved(Event $event): void
    {
        $this->projections->handle($event);
    }

    public function deleting(Event $event): void
    {
        $this->deletion->handle($event);
    }
}
