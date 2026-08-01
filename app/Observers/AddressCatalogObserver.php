<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use App\Support\Cache\SelectionCatalogCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class AddressCatalogObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SelectionCatalogCache $selectionCatalogCache) {}

    public function saved(State|City $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged()) {
            $this->selectionCatalogCache->bustAddress();
        }
    }

    public function deleted(State|City $model): void
    {
        $this->selectionCatalogCache->bustAddress();
    }
}
