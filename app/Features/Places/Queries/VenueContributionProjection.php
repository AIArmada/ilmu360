<?php

declare(strict_types=1);

namespace App\Features\Places\Queries;

use AIArmada\Events\Models\FacilityType;
use AIArmada\Events\Models\VenueFacility;
use App\Features\Places\Models\Venue;
use App\Platform\Models\MorphModelResolver;
use BackedEnum;
use RuntimeException;

final readonly class VenueContributionProjection implements \App\Shared\Contributions\ContributionProjection
{
    use \App\Shared\Contributions\Support\BuildsContributionDescriptors;
    use \App\Shared\Contributions\Support\BuildsContributionSubjectState;

    public function supports(string $entityType): bool
    {
        return $entityType === 'venue';
    }

    public function contractFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        throw new RuntimeException('Unsupported contribution entity type.');
    }

    /** @return array<string, mixed> */
    public function stateFor(string $subjectId): array
    {
        return $this->venueState($this->resolve($subjectId));
    }

    public function updateRulesFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        throw new RuntimeException('Unsupported contribution entity type.');
    }

    private function resolve(string $subjectId): Venue
    {
        $modelClass = MorphModelResolver::resolve('venue');
        $model = $modelClass::query()->findOrFail($subjectId);

        if (! $model instanceof Venue) {
            throw new RuntimeException('Expected the resolved contribution subject to be a venue.');
        }

        return $model;
    }

    /** @return array<string, mixed> */
    private function venueState(Venue $venue): array
    {
        $venue->loadMissing(['addresses', 'contactMethods', 'socialProfiles', 'facilities.facilityType']);

        return [
            'name' => $venue->name,
            'venue_type' => $venue->venue_type instanceof BackedEnum ? $venue->venue_type->value : (string) $venue->venue_type,
            'facilities' => $venue->facilities
                ->map(static function (VenueFacility $facility): string {
                    $facilityType = $facility->getRelation('facilityType');

                    return $facilityType instanceof FacilityType ? $facilityType->code : '';
                })
                ->filter(static fn (string $code): bool => $code !== '')
                ->values()
                ->all(),
            'address' => $this->addressState($venue->primaryAddress()),
            'contactMethods' => $this->contactMethodsState($venue->contactMethods),
            'social_media' => $this->socialMediaState($venue->socialProfiles),
        ];
    }
}
