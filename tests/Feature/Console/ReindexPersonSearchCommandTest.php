<?php

use App\Features\Persons\Models\Person;
use App\Features\Persons\Services\PersonSearchService;
use Illuminate\Support\Facades\DB;

it('rebuilds stale person search rows and searchable names', function () {
    $person = Person::factory()->create([
        'name' => 'Nurul Akma',
        'middle_name' => 'Ibn',
        'family_name' => 'Rahman',
        'status' => 'verified',
    ]);

    DB::table('persons')
        ->where('id', $person->id)
        ->update(['searchable_name' => '']);

    $this->artisan('persons:reindex-search', ['--chunk' => 1])
        ->expectsOutputToContain('Reindexed 1 person search record(s).')
        ->assertSuccessful();

    expect((string) DB::table('persons')->where('id', $person->id)->value('searchable_name'))
        ->not->toBeEmpty()
        ->and(app(PersonSearchService::class)->publicSearchIds('Nurul'))
        ->not->toBeEmpty();
});
