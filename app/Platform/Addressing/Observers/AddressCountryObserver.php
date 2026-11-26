<?php

declare(strict_types=1);

namespace App\Platform\Addressing\Observers;

use AIArmada\Addressing\Features\Geography\Actions\GuardAddressCountryDeletionAction;
use AIArmada\Addressing\Features\Geography\Models\AddressCountry;
use App\Shared\Contracts\AddressCatalogEffects;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class AddressCountryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private AddressCatalogEffects $catalogEffects,
        private GuardAddressCountryDeletionAction $deletionGuard,
    ) {}

    public function saved(AddressCountry $country): void
    {
        if ($country->wasRecentlyCreated || $country->wasChanged()) {
            $this->catalogEffects->invalidateLocationCatalogs();
        }
    }

    public function deleted(AddressCountry $country): void
    {
        $this->catalogEffects->invalidateLocationCatalogs();
    }

    public function deleting(AddressCountry $country): void
    {
        $this->deletionGuard->handle((string) $country->getKey());
    }
}
