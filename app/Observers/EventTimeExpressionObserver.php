<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Events\Models\EventTimeExpression;
use App\Models\Event;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class EventTimeExpressionObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(EventTimeExpression $expression): void
    {
        $this->syncEventIndex($expression);
    }

    public function deleted(EventTimeExpression $expression): void
    {
        $this->syncEventIndex($expression);
    }

    private function syncEventIndex(EventTimeExpression $expression): void
    {
        $event = Event::query()->find($expression->event_id);

        if (! $event instanceof Event) {
            return;
        }

        if ($event->shouldBeSearchable()) {
            $event->searchable();
        } elseif ($event->wasSearchableBeforeUpdate()) {
            $event->unsearchable();
        }
    }
}
