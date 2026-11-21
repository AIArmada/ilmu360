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
use App\Shared\Contracts\AddressChangeEffects;

final readonly class SyncAddressChangeEffectsAction implements AddressChangeEffects
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
            $id = $subject['id'];
            $name = $subject['name'];

            switch ($subject['morph_type']) {
                case 'institution':
                    $this->institutionWorkflow->syncSlugsForName($name);
                    $this->institutionWorkflow->syncSearchProjection($id);
                    $this->eventAuthoring->syncSearchableEventsByIds($subject['event_ids']);
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
                    $this->eventAuthoring->syncSearchableEventsByIds($subject['event_ids']);
                    $this->publicListingsCache->bustMajlisListing();

                    break;
            }
        }

        if (in_array('institution', $morphTypes, true)) {
            $this->institutionCacheVersion->bump();
        }

        if (in_array('person', $morphTypes, true)) {
            $this->personCacheVersion->bump();
        }
    }
}
