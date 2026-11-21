<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Places\Api\VenueWorkflow;
use App\Shared\Contracts\AddressableChangeEffects;
use App\Shared\Contracts\AddressChangeEffects;

final readonly class SyncAddressChangeEffectsAction implements AddressableChangeEffects, AddressChangeEffects
{
    public function __construct(
        private EventAuthoring $eventAuthoring,
        private InstitutionWorkflow $institutionWorkflow,
        private PersonWorkflow $personWorkflow,
        private VenueWorkflow $venueWorkflow,
        private InstitutionListingCacheVersion $institutionCacheVersion,
        private PersonListingCacheVersion $personCacheVersion,
        private PublicListingsCacheInvalidator $publicListingsCache,
    ) {}

    public function apply(array $subjects, array $morphTypes): void
    {
        foreach ($subjects as $subject) {
            $this->syncAddressable($subject['morph_type'], $subject['id'], $subject['name'], $subject['event_ids']);
        }

        $this->bumpAffectedDirectoryVersions($morphTypes);
    }

    /** @param list<string> $morphTypes */
    public function bumpAffectedDirectoryVersions(array $morphTypes): void
    {
        if (in_array('institution', $morphTypes, true)) {
            $this->institutionCacheVersion->bump();
        }

        if (in_array('person', $morphTypes, true)) {
            $this->personCacheVersion->bump();
        }
    }

    /**
     * @param  list<string>  $eventIds
     * @param  array<string, mixed>|null  $deletedAddress
     */
    public function syncAddressable(string $morphType, string $id, string $name, array $eventIds, ?array $deletedAddress = null): void
    {
        switch ($morphType) {
            case 'institution':
                $this->institutionWorkflow->syncSlugsForName($name);
                $this->institutionWorkflow->syncSearchProjection($id);
                $this->eventAuthoring->syncSearchableEventsByIds($eventIds);
                $this->publicListingsCache->bustMajlisListing();
                break;
            case 'person':
                if ($deletedAddress !== null) {
                    $this->personWorkflow->syncSlugAfterAddressDeleted($id, $deletedAddress);
                } else {
                    $this->personWorkflow->syncSlugsForName($name);
                }
                $this->eventAuthoring->syncEventSlugsForPersonName($name);
                $this->personWorkflow->syncSearchProjection($id);
                $this->publicListingsCache->bustMajlisListing();
                break;
            case 'venue':
                $this->venueWorkflow->syncSlugsForName($name);
                $this->eventAuthoring->syncSearchableEventsByIds($eventIds);
                $this->publicListingsCache->bustMajlisListing();
                break;
        }
    }
}
