<?php

namespace App\Platform\Addressing\Observers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use AIArmada\Addressing\Support\AddressCountryResolver;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Places\Api\VenueWorkflow;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class AddressObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected EventAuthoring $eventAuthoring,
        protected InstitutionWorkflow $institutionWorkflow,
        protected PersonWorkflow $personWorkflow,
        protected VenueWorkflow $venueWorkflow,
        protected InstitutionListingCacheVersion $institutionCacheVersion,
        protected PersonListingCacheVersion $personCacheVersion,
        protected PublicListingsCacheInvalidator $publicListingsCache,
        protected AddressCountryResolver $addressingCountryResolver,
    ) {}

    public function saving(Address $address): void
    {
        if (filled($address->country_id)) {
            return;
        }

        $defaultCountryCode = config('addressing.defaults.country_code');

        if (is_string($defaultCountryCode) && trim($defaultCountryCode) !== '') {
            $address->country_id = $this->addressingCountryResolver->resolveId($defaultCountryCode);
        }
    }

    public function saved(Address $address): void
    {
        if (! $address->wasRecentlyCreated && ! $address->wasChanged()) {
            return;
        }

        $this->syncInstitutionSlug($address);
        $this->bumpDirectoryVersionsForAddress($address);
    }

    public function deleted(Address $address): void
    {
        $this->syncInstitutionSlug($address);
        $this->bumpDirectoryVersionsForAddress($address);
    }

    private function syncInstitutionSlug(Address $address): void
    {
        $address->loadMissing('addressableLinks.addressable');

        foreach ($address->addressableLinks as $link) {
            $addressable = $link->addressable;

            if (! $addressable instanceof Model) {
                continue;
            }

            $id = (string) $addressable->getKey();
            $name = (string) ($addressable->name ?? '');

            switch ($addressable->getMorphClass()) {
                case 'institution':
                    $this->institutionWorkflow->syncSlugsForName($name);
                    $this->institutionWorkflow->syncSearchProjection($id);
                    $this->eventAuthoring->syncSearchableEventsByIds($addressable->events()->pluck('id')->all());
                    $this->publicListingsCache->bustMajlisListing();

                    break;
                case 'person':
                    $this->personWorkflow->syncSlugsForName($name);
                    $this->eventAuthoring->syncEventSlugsForPersonName($name);
                    $this->personWorkflow->syncSearchProjection($id);
                    $this->publicListingsCache->bustMajlisListing();

                    break;
                case 'venue':
                    $this->venueWorkflow->syncSlugsForName($name);
                    $this->eventAuthoring->syncSearchableEventsByIds($addressable->events()->pluck('id')->all());
                    $this->publicListingsCache->bustMajlisListing();

                    break;
            }
        }
    }

    private function bumpDirectoryVersionsForAddress(Address $address): void
    {
        $address->loadMissing('addressableLinks');

        $addressableMorphTypes = $address->addressableLinks
            ->map(static fn (Addressable $link): string => (string) $link->addressable_type)
            ->values()
            ->all();

        if (in_array('institution', $addressableMorphTypes, true)) {
            $this->institutionCacheVersion->bump();
        }

        if (in_array('person', $addressableMorphTypes, true)) {
            $this->personCacheVersion->bump();
        }
    }

    /**
     * @param  EloquentCollection<int, Event>  $events
     */
}
