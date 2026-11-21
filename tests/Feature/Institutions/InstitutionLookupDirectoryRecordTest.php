<?php

declare(strict_types=1);

use App\Features\Institutions\Api\InstitutionDirectoryRecord;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Institutions\Models\Institution;

it('exposes institution directory records without returning Eloquent models', function (): void {
    $institution = Institution::factory()->create(['name' => 'A Community Institution']);
    $id = (string) $institution->getKey();
    $lookup = app(InstitutionLookup::class);

    foreach ([$lookup->institutionsByIds([$id]), $lookup->followedInstitutions([$id])] as $records) {
        expect($records)->toHaveCount(1)
            ->and($records[0])->toBeInstanceOf(InstitutionDirectoryRecord::class)
            ->and($records[0]->id)->toBe($id)
            ->and($records[0]->name)->toBe('A Community Institution');
    }
});
