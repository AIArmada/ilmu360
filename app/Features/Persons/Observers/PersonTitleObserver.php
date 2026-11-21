<?php

declare(strict_types=1);

namespace App\Features\Persons\Observers;

use App\Features\Persons\Actions\Projection\SyncPersonTitleEffectsAction;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Framework observer adapter; the feature Action owns the behavior. */
final readonly class PersonTitleObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SyncPersonTitleEffectsAction $effects) {}

    public function saved(Title|TitleAssignment $model): void
    {
        $this->effects->saved($model);
    }

    public function deleted(Title|TitleAssignment $model): void
    {
        $this->effects->deleted($model);
    }
}
