<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use App\Features\Events\Actions\Projection\InvalidateEventKeyPersonCardsAction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Eloquent notification adapter; the feature Action owns cache effects. */
final readonly class EventKeyPersonObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private InvalidateEventKeyPersonCardsAction $effects) {}

    public function created(): void
    {
        $this->effects->handle();
    }

    public function updated(): void
    {
        $this->effects->handle();
    }

    public function deleted(): void
    {
        $this->effects->handle();
    }
}
