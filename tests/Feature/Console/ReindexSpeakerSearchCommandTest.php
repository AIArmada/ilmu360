<?php

use App\Models\Speaker;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Support\Facades\DB;

it('rebuilds stale speaker search rows and searchable names', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Nurul Akma',
        'pre_nominal' => ['ustazah'],
        'status' => 'verified',
        'is_active' => true,
    ]);

    DB::table('speakers')
        ->where('id', $speaker->id)
        ->update(['searchable_name' => '']);

    DB::table('speaker_search_terms')
        ->where('speaker_id', $speaker->id)
        ->delete();

    expect(app(SpeakerSearchService::class)->publicSearchIds('ustazah'))->toBe([]);

    $this->artisan('speakers:reindex-search', ['--chunk' => 1])
        ->expectsOutputToContain('Reindexed 1 speaker search record(s).')
        ->assertSuccessful();

    expect((string) DB::table('speakers')->where('id', $speaker->id)->value('searchable_name'))
        ->toContain('ustazah')
        ->and(DB::table('speaker_search_terms')->where('speaker_id', $speaker->id)->pluck('term')->all())
        ->toContain('ustazah')
        ->and(app(SpeakerSearchService::class)->publicSearchIds('ustazah'))
        ->toContain((string) $speaker->id);
});
