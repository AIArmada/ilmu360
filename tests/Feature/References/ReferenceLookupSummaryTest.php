<?php

declare(strict_types=1);

use App\Features\References\Api\ReferenceLookup;
use App\Features\References\Api\ReferenceSummary;
use App\Features\References\Models\Reference;

it('returns immutable reference summaries for follow and admin directory readers', function (): void {
    $first = Reference::factory()->create(['title' => 'B Reference']);
    $second = Reference::factory()->create(['title' => 'A Reference']);
    $ids = [(string) $first->getKey(), (string) $second->getKey()];

    $reader = app(ReferenceLookup::class);
    foreach ([$reader->followedReferences($ids), $reader->referencesByIds($ids)] as $summaries) {
        expect($summaries)->toHaveCount(2)
            ->and($summaries[0])->toBeInstanceOf(ReferenceSummary::class)
            ->and(array_map(static fn (ReferenceSummary $summary): string => $summary->id, $summaries))
            ->toBe([(string) $second->getKey(), (string) $first->getKey()]);
    }
});
