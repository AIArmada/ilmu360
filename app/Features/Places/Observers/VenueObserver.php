<?php

declare(strict_types=1);

namespace App\Features\Places\Observers;

use App\Features\Places\Actions\Projection\SyncVenueDirectoryEffectsAction;
use App\Features\Places\Models\Venue;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Eloquent notification adapter; derived projections run in the owning Action. */
final readonly class VenueObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SyncVenueDirectoryEffectsAction $effects) {}

    public function saved(Venue $record): void
    {
        $this->effects->saved($record);
    }

    public function deleted(Venue $record): void
    {
        $this->effects->deleted($record);
    }
}
