<?php

declare(strict_types=1);

namespace App\Features\References\Observers;

use App\Features\References\Actions\Projection\SyncReferenceDirectoryEffectsAction;
use App\Features\References\Models\Reference;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Eloquent lifecycle adapter; cross-owner projections are handled by the Action. */
final readonly class ReferenceObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SyncReferenceDirectoryEffectsAction $effects) {}

    public function saved(Reference $record): void
    {
        $this->effects->saved($record);
    }

    public function updated(Reference $record): void
    {
        $this->effects->updated($record);
    }

    public function deleted(Reference $record): void
    {
        $this->effects->deleted($record);
    }
}
