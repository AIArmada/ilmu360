<?php

declare(strict_types=1);

namespace App\Platform\Addressing\Observers;

use AIArmada\Addressing\Features\Geography\Models\City;
use AIArmada\Addressing\Features\Geography\Models\State;
use App\Shared\Cache\SelectionCatalog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class AddressCatalogObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SelectionCatalog $selectionCatalog) {}

    public function saved(State|City $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged()) {
            $this->selectionCatalog->bustAddress();
        }
    }

    public function deleted(State|City $model): void
    {
        $this->selectionCatalog->bustAddress();
    }
}
