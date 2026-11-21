<?php

declare(strict_types=1);

use App\Features\Persons\Api\PersonDirectoryRecord;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Models\Person;

it('exposes speaker directory results without returning Eloquent models', function (): void {
    $person = Person::factory()->create(['name' => 'Speaker Record']);
    $id = (string) $person->getKey();

    $lookup = app(PersonLookup::class);
    foreach ([$lookup->followedPersons([$id]), $lookup->personsByIds([$id])] as $records) {
        expect($records)->toHaveCount(1)
            ->and($records[0])->toBeInstanceOf(PersonDirectoryRecord::class)
            ->and($records[0]->id)->toBe($id)
            ->and($records[0]->name)->toBe('Speaker Record');
    }
});
