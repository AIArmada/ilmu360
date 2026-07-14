<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Validation\ValidationException;

class AddressCountryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        private readonly PublicListingsCache $publicListingsCache,
    ) {}

    public function saving(AddressCountry $country): void
    {
        if (filled($country->entity_type)) {
            return;
        }

        $country->entity_type = 'country';
    }

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
        $this->publicDirectoryCacheVersion->bumpAll();
        $this->publicListingsCache->bustMajlisListing();
    }
}
