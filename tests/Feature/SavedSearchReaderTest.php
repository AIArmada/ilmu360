<?php

declare(strict_types=1);

use App\Contracts\SavedSearchReader;
use App\Data\SavedSearchSnapshot;
use App\Models\SavedSearch;
use App\Models\User;
use Carbon\CarbonImmutable;

it('returns the latest owner summaries without exposing another polymorphic owner', function (): void {
    $owner = User::factory()->create();
    $oldest = SavedSearch::factory()->create(['user_id' => $owner->id, 'created_at' => '2025-01-01 00:00:00']);
    $newest = SavedSearch::factory()->create(['user_id' => $owner->id, 'created_at' => '2025-03-01 00:00:00']);
    $middle = SavedSearch::factory()->create(['user_id' => $owner->id, 'created_at' => '2025-02-01 00:00:00']);
    SavedSearch::factory()->create(['user_type' => 'other-owner', 'user_id' => $owner->id, 'created_at' => '2025-04-01 00:00:00']);
    SavedSearch::factory()->create(['created_at' => '2025-05-01 00:00:00']);

    $reader = app(SavedSearchReader::class);
    $limited = $reader->forOwner($owner->getMorphClass(), $owner->id, 2);

    expect(array_column($limited, 'id'))->toBe([$newest->id, $middle->id])
        ->and(array_column($reader->forOwner($owner->getMorphClass(), $owner->id), 'id'))
        ->toBe([$newest->id, $middle->id, $oldest->id])
        ->and($limited[0])->toBeInstanceOf(SavedSearchSnapshot::class);
});

it('returns detached search content and immutable timestamp values', function (string|array|null $query): void {
    $savedSearch = SavedSearch::factory()->create([
        'name' => 'Original name',
        'query' => $query,
        'filters' => ['language_codes' => ['ms']],
        'notify' => 'weekly',
        'lat' => 3.1234567,
        'lng' => 101.7654321,
        'radius_km' => 12,
        'created_at' => '2025-02-03 04:05:06',
    ]);
    $reader = app(SavedSearchReader::class);

    $snapshot = $reader->find($savedSearch->id);
    $savedSearch->update(['name' => 'Changed name', 'query' => 'changed', 'filters' => []]);

    expect($snapshot)->toEqual(new SavedSearchSnapshot(
        id: $savedSearch->id,
        userType: (new User)->getMorphClass(),
        userId: $savedSearch->user_id,
        name: 'Original name',
        query: $query,
        filters: ['language_codes' => ['ms']],
        notify: 'weekly',
        lat: 3.1234567,
        lng: 101.7654321,
        radiusKm: 12,
        createdAt: CarbonImmutable::parse('2025-02-03 04:05:06', 'UTC'),
    ));
})->with([
    'text query' => ['kuliah'],
    'structured query' => [['search' => 'kuliah']],
    'empty query' => [null],
]);

it('streams subscribed candidates while preserving unknown notification cadences', function (): void {
    $owner = User::factory()->create();
    $searches = SavedSearch::factory()->count(4)->create(['user_id' => $owner->id, 'notify' => 'daily']);
    $searches[0]->update(['notify' => 'instant']);
    $searches[1]->update(['notify' => 'weekly']);
    $searches[2]->update(['notify' => 'unrecognized']);
    SavedSearch::factory()->create(['user_id' => $owner->id, 'notify' => 'off']);

    $candidates = iterator_to_array(app(SavedSearchReader::class)->notificationCandidates());

    expect(array_column($candidates, 'id'))->toEqualCanonicalizing($searches->modelKeys())
        ->and(array_column($candidates, 'notify'))->toEqualCanonicalizing(['instant', 'weekly', 'unrecognized', 'daily']);
});

it('captures complete restoration attributes only for the requested owner', function (): void {
    $owner = User::factory()->create();
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $owner->id,
        'meta' => ['source' => 'restoration'],
        'created_at' => '2025-01-01 02:03:04',
    ])->refresh();
    SavedSearch::factory()->create(['user_type' => 'other-owner', 'user_id' => $owner->id]);
    SavedSearch::factory()->create();

    $snapshot = app(SavedSearchReader::class)->restorationSnapshotForOwner($owner->getMorphClass(), $owner->id);

    expect($snapshot)->toBe([$savedSearch->attributesToArray()]);
});

it('reports missing sources after deletion without changing an existing snapshot', function (): void {
    $savedSearch = SavedSearch::factory()->create(['name' => 'Retained value']);
    $reader = app(SavedSearchReader::class);
    $snapshot = $reader->find($savedSearch->id);
    expect($reader->exists($savedSearch->id))->toBeTrue();

    $savedSearch->delete();

    expect($reader->find($savedSearch->id))->toBeNull()
        ->and($reader->exists($savedSearch->id))->toBeFalse()
        ->and($snapshot?->name)->toBe('Retained value');
});
