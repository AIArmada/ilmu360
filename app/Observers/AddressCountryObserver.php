<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use App\Features\Directory\Api\PublicDirectoryCacheVersions;
use App\Services\PublicListingsCache;
use App\Shared\Cache\SelectionCatalogCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Validation\ValidationException;

class AddressCountryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PublicDirectoryCacheVersions $publicDirectoryCacheVersions,
        private readonly PublicListingsCache $publicListingsCache,
        private readonly SelectionCatalogCache $selectionCatalogCache,
    ) {}

    public function saved(AddressCountry $country): void
    {
        if (! $country->wasRecentlyCreated && ! $country->wasChanged()) {
            return;
        }

        $this->flushCountryCaches();
    }

    public function deleted(AddressCountry $country): void
    {
        $this->flushCountryCaches();
    }

    public function deleting(AddressCountry $country): void
    {
        $recordKey = (string) $country->getKey();

        if (
            AddressArea::query()->where('country_id', $recordKey)->exists()
            || Address::query()->where('country_id', $recordKey)->exists()
        ) {
            throw ValidationException::withMessages([
                'country' => 'Delete or reassign this country\'s areas and addresses before deleting it.',
            ]);
        }
    }

    private function flushCountryCaches(): void
    {
        $this->publicDirectoryCacheVersions->bumpAll();
        $this->publicListingsCache->bustMajlisListing();
        $this->selectionCatalogCache->bustAddress();
    }
}
