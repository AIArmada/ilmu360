<?php

declare(strict_types=1);

namespace App\Features\Persons\Observers;

use AIArmada\Persons\Models\PersonName;
use App\Features\Persons\Actions\Projection\SyncPersonNameSearchAction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Eloquent notification adapter; the owning Action maintains the search projection. */
final readonly class PersonNameObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SyncPersonNameSearchAction $effects) {}

    public function saved(PersonName $name): void
    {
        $this->effects->handle($name);
    }

    public function deleted(PersonName $name): void
    {
        $this->effects->handle($name);
    }
}
