<?php

declare(strict_types=1);

use AIArmada\Events\Platform\DefaultEventSearchRelationProvider;
use App\Features\Events\Queries\EventCardRelationshipProvider;

it('composes package relations with application-owned event card relations', function (): void {
    $relations = new EventCardRelationshipProvider(new DefaultEventSearchRelationProvider)->relations();

    expect($relations)
        ->toContain('classifications.term', 'timeExpressions', 'institution.addresses.country')
        ->toHaveKey('references')
        ->not->toContain('classifications');
});
