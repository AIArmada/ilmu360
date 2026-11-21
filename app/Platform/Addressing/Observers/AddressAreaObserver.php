<?php

declare(strict_types=1);

namespace App\Platform\Addressing\Observers;

use AIArmada\Addressing\Models\AddressArea;
use App\Platform\Addressing\Actions\GuardAddressAreaDeletionAction;
use App\Shared\Contracts\AddressCatalogEffects;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class AddressAreaObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private AddressCatalogEffects $catalogEffects,
        private GuardAddressAreaDeletionAction $deletionGuard,
    ) {}

    public function saved(AddressArea $addressArea): void
    {
        if ($addressArea->wasRecentlyCreated || $addressArea->wasChanged()) {
            $this->catalogEffects->invalidateLocationCatalogs();
        }
    }

    public function deleted(AddressArea $addressArea): void
    {
        $this->catalogEffects->invalidateLocationCatalogs();
    }

    public function deleting(AddressArea $addressArea): void
    {
        $this->deletionGuard->handle($addressArea);
    }
}
