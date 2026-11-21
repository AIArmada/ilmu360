<?php

declare(strict_types=1);

use App\Features\Events\Queries\SearchResultRelations;

it('removes eager occurrence media from discovery candidate queries without dropping other relations', function (): void {
    $relations = [
        'languageRecords',
        'displayOccurrence.media',
        'displayOccurrence.media' => static fn (): null => null,
        'primaryOccurrence',
        'persons.media',
    ];

    $filtered = SearchResultRelations::forCards($relations);

    expect(array_keys($filtered))->not->toContain('displayOccurrence.media')
        ->and(array_values($filtered))->toContain('languageRecords', 'primaryOccurrence', 'persons.media');
});
