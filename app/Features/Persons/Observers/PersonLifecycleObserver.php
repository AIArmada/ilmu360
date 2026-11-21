<?php

declare(strict_types=1);

namespace App\Features\Persons\Observers;

use App\Features\Persons\Actions\GuardPersonDeletionAction;
use App\Features\Persons\Actions\PreparePersonSaveAction;
use App\Features\Persons\Models\Person;

final readonly class PersonLifecycleObserver
{
    public function __construct(
        private GuardPersonDeletionAction $guardDeletion,
        private PreparePersonSaveAction $prepareSave,
    ) {}

    public function deleting(Person $person): void
    {
        $this->guardDeletion->handle($person);
    }

    public function saving(Person $person): void
    {
        $this->prepareSave->handle($person);
    }
}
