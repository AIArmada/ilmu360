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
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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

        if (! $subject instanceof Model) {
            return;
        }

        $id = (string) $subject->getKey();
        $name = (string) ($subject->name ?? '');

        switch ($subject->getMorphClass()) {
            case 'institution':
                $this->institutionWorkflow->syncSlugsForName($name);
                $this->institutionWorkflow->syncSearchProjection($id);
                $this->eventAuthoring->syncSearchableEventsByIds($this->eventIds($subject));
                $this->publicListingsCache->bustMajlisListing();

                break;
            case 'person':
                if ($deletedAddress instanceof Address) {
                    $this->personWorkflow->syncSlugAfterAddressDeleted($id, [
                        'city' => $deletedAddress->city,
                        'state' => $deletedAddress->state,
                        'country_id' => $deletedAddress->country_id,
                        'country_code' => $deletedAddress->country_code,
                    ]);
                } else {
                    $this->personWorkflow->syncSlugsForName($name);
                }

                $this->eventAuthoring->syncEventSlugsForPersonName($name);
                $this->personWorkflow->syncSearchProjection($id);
                $this->publicListingsCache->bustMajlisListing();

                break;
            case 'venue':
                $this->venueWorkflow->syncSlugsForName($name);
                $this->eventAuthoring->syncSearchableEventsByIds($this->eventIds($subject));
                $this->publicListingsCache->bustMajlisListing();

                break;
        }
    }

    /**
     * @return list<string>
     */
    private function eventIds(Model $model): array
    {
        $events = $model->getRelationValue('events');

        if (! $events instanceof Collection) {
            return [];
        }

        return $events->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
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
