<?php

declare(strict_types=1);

namespace App\Features\Institutions\Observers;

use App\Features\Institutions\Actions\Projection\SyncInstitutionDirectoryEffectsAction;
use App\Features\Institutions\Models\Institution;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Eloquent lifecycle adapter; cross-owner projections are handled by the Action. */
final readonly class InstitutionObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SyncInstitutionDirectoryEffectsAction $effects) {}

    public function saved(Institution $record): void
    {
        $this->effects->saved($record);
    }

    public function deleted(Institution $record): void
    {
        $this->effects->deleted($record);
    }
}
