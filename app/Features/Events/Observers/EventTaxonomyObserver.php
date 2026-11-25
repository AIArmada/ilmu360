<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use AIArmada\Events\Features\Taxonomy\Models\EventTaxonomy;
use App\Features\Events\Actions\Projection\InvalidateEventTaxonomyCatalogAction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Eloquent notification adapter; derived projections run in the owning Action. */
final readonly class EventTaxonomyObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private InvalidateEventTaxonomyCatalogAction $effects) {}

    public function saved(EventTaxonomy $record): void
    {
        $this->effects->saved($record);
    }

    public function deleted(EventTaxonomy $record): void
    {
        $this->effects->deleted();
    }
}
