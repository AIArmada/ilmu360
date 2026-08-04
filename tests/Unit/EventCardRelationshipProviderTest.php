<?php

declare(strict_types=1);

use AIArmada\Events\Resolvers\DefaultEventSearchRelationProvider;
use App\Support\EventDiscovery\EventCardRelationshipProvider;

it('composes package relations with application-owned event card relations', function (): void {
    $relations = (new EventCardRelationshipProvider(new DefaultEventSearchRelationProvider))->relations();

    expect($relations)
        ->toContain('classifications.term', 'timeExpressions', 'references', 'institution.addresses.country')
        ->not->toContain('classifications');
});
