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
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Venue;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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

            if ($addressable instanceof Institution) {
                $this->institutionWorkflow->syncSlugsForName($addressable->name);
                $this->syncSearchableModel($addressable);
                $this->syncSearchableEvents($addressable->events()->get(['events.*']));
                $this->publicListingsCache->bustMajlisListing();

                continue;
            }

            if ($addressable instanceof Person) {
                $this->personWorkflow->syncSlugsForName($addressable->name);
                $this->eventAuthoring->syncEventSlugsForPersonName($addressable->name);
                $this->syncSearchableModel($addressable);
                $this->publicListingsCache->bustMajlisListing();

                continue;
            }

            if ($addressable instanceof Venue) {
                $this->venueWorkflow->syncSlugsForName($addressable->name);
                $this->syncSearchableEvents($addressable->events()->get(['events.*']));
                $this->publicListingsCache->bustMajlisListing();
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

    private function syncSearchableModel(Institution|Person $model): void
    {
        if ($model->shouldBeSearchable()) {
            $model->searchable();

            return;
        }

        $model->unsearchable();
    }

    /**
     * @param  EloquentCollection<int, Event>  $events
     */
    private function syncSearchableEvents(EloquentCollection $events): void
    {
        $searchableEvents = $events
            ->filter(fn (Event $event): bool => $event->shouldBeSearchable())
            ->values();

        if ($searchableEvents->isNotEmpty()) {
            $searchableEvents->searchable();
        }

        $unsearchableEvents = $events
            ->reject(fn (Event $event): bool => $event->shouldBeSearchable())
            ->values();

        if ($unsearchableEvents->isNotEmpty()) {
            $unsearchableEvents->unsearchable();
        }
    }
}
