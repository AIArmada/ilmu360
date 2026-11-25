<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use AIArmada\Events\Features\Taxonomy\Models\EventTerm;
use App\Features\Events\Actions\Projection\InvalidateEventTermCatalogAction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Eloquent notification adapter; derived projections run in the owning Action. */
final readonly class EventTermObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private InvalidateEventTermCatalogAction $effects) {}

    public function saved(EventTerm $record): void
    {
        $this->effects->saved($record);
    }

    public function deleted(EventTerm $record): void
    {
        $this->effects->deleted();
    }
}
