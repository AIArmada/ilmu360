<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use App\Features\Events\Actions\Lifecycle\ApplyEventLifecycleEffectsAction;
use App\Features\Events\Models\Event;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class EventObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private ApplyEventLifecycleEffectsAction $effects) {}

    public function creating(Event $event): void
    {
        $this->effects->creating($event);
    }

    public function created(Event $event): void
    {
        $this->effects->created($event);
    }

    public function updated(Event $event): void
    {
        $this->effects->updated($event);
    }

    public function saved(Event $event): void
    {
        $this->effects->saved($event);
    }

    public function deleted(Event $event): void
    {
        $this->effects->deleted($event);
    }
}
