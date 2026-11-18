<?php

namespace App\Platform\Addressing\Observers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
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

class AddressableObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly EventAuthoring $eventAuthoring,
        private readonly InstitutionWorkflow $institutionWorkflow,
        private readonly PersonWorkflow $personWorkflow,
        private readonly VenueWorkflow $venueWorkflow,
        private readonly InstitutionListingCacheVersion $institutionCacheVersion,
        private readonly PersonListingCacheVersion $personCacheVersion,
        private readonly PublicListingsCacheInvalidator $publicListingsCache,
    ) {}

    public function created(Addressable $addressable): void
    {
        $addressable->loadMissing('address');

        $address = $addressable->address;

        if ($address instanceof Address) {
            $this->bumpDirectoryVersionsForAddress($address);
        }

        $this->syncAddressable($addressable);
    }

    public function deleted(Addressable $addressable): void
    {
        $addressable->loadMissing('address');

        $address = $addressable->address;

        if ($address instanceof Address) {
            $this->bumpDirectoryVersionsForAddress($address);
        }

        $this->syncAddressable($addressable, $address instanceof Address ? $address : null);
    }

    private function syncAddressable(Addressable $addressable, ?Address $deletedAddress = null): void
    {
        $addressable->loadMissing('addressable');

        $subject = $addressable->addressable;

        if ($subject instanceof Institution) {
            $this->institutionWorkflow->syncSlugsForName($subject->name);
            $this->syncSearchableModel($subject);
            $this->syncSearchableEvents($subject->events()->get(['events.*']));
            $this->publicListingsCache->bustMajlisListing();

            return;
        }

        if ($subject instanceof Person) {
            if ($deletedAddress instanceof Address) {
                $this->personWorkflow->syncSlugAfterAddressDeleted((string) $subject->getKey(), [
                    'city' => $deletedAddress->city,
                    'state' => $deletedAddress->state,
                    'country_id' => $deletedAddress->country_id,
                    'country_code' => $deletedAddress->country_code,
                ]);
            } else {
                $this->personWorkflow->syncSlugsForName($subject->name);
            }
            $this->eventAuthoring->syncEventSlugsForPersonName($subject->name);
            $this->syncSearchableModel($subject);
            $this->publicListingsCache->bustMajlisListing();

            return;
        }

        if ($subject instanceof Venue) {
            $this->venueWorkflow->syncSlugsForName($subject->name);
            $this->syncSearchableEvents($subject->events()->get(['events.*']));
            $this->publicListingsCache->bustMajlisListing();
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
