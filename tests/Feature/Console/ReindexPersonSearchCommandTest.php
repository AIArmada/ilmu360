<?php

use App\Models\Person;
use App\Support\Search\PersonSearchService;
use Illuminate\Support\Facades\DB;

it('rebuilds stale person search rows and searchable names', function () {
    $person = Person::factory()->create([
        'name' => 'Nurul Akma',
        'pre_nominal' => ['ustazah'],
        'status' => 'verified',
    ]);

    DB::table('persons')
        ->where('id', $person->id)
        ->update(['searchable_name' => '']);

    DB::table('speaker_search_terms')
        ->where('speaker_id', $person->id)
        ->delete();

    expect(app(PersonSearchService::class)->publicSearchIds('ustazah'))->toBe([]);

    $this->artisan('persons:reindex-search', ['--chunk' => 1])
        ->expectsOutputToContain('Reindexed 1 person search record(s).')
        ->assertSuccessful();

    expect((string) DB::table('persons')->where('id', $person->id)->value('searchable_name'))
        ->toContain('ustazah')
        ->and(DB::table('speaker_search_terms')->where('speaker_id', $person->id)->pluck('term')->all())
        ->toContain('ustazah')
        ->and(app(PersonSearchService::class)->publicSearchIds('ustazah'))
        ->toContain((string) $person->id);
});
