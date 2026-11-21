<?php

declare(strict_types=1);

namespace App\Features\Persons\Observers;

use App\Features\Persons\Actions\Projection\SyncPersonDirectoryEffectsAction;
use App\Features\Persons\Models\Person;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Eloquent lifecycle adapter; projection and cross-owner effects live in the Action. */
final readonly class PersonObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SyncPersonDirectoryEffectsAction $effects) {}

    public function saved(Person $person): void
    {
        $this->effects->saved($person);
    }

    public function deleted(Person $person): void
    {
        $this->effects->deleted($person);
    }
}
