<?php

declare(strict_types=1);

use App\Features\Persons\Actions\SyncPersonSearchProjectionAction;
use App\Features\Persons\Api\PersonSearchCache;
use App\Features\Persons\Persistence\PersonSearchCacheStore;
use App\Support\Search\PersonSearchService;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

it('binds the person public-search cache api to the directory cache store', function (): void {
    expect(app(PersonSearchCache::class))
        ->toBeInstanceOf(PersonSearchCacheStore::class);
});

it('shares one advancing version key across both public person-search bump entrypoints', function (): void {
    $service = app(PersonSearchService::class);
    $search = 'Shared Person Cache Version';

    $resultKey = static fn (int $version): string => sprintf(
        'person_search_public:%d:%s',
        $version,
        md5((string) $service->normalizedSearch($search)),
    );

    expect(Cache::get('person_search_public_version_v2', 2))->toBe(2);

    Cache::put($resultKey(2), ['stale-person-id'], now()->addMinutes(10));

    expect($service->publicSearchIds($search))->toBe(['stale-person-id']);

    $service->bustPublicSearchCache();

    expect(Cache::get('person_search_public_version_v2'))->toBe(3)
        ->and($service->publicSearchIds($search))->toBe([]);

    Cache::put($resultKey(3), ['stale-person-id'], now()->addMinutes(10));

    expect($service->publicSearchIds($search))->toBe(['stale-person-id']);

    app(SyncPersonSearchProjectionAction::class)->bustPublicSearchCache();

    expect(Cache::get('person_search_public_version_v2'))->toBe(4)
        ->and($service->publicSearchIds($search))->toBe([]);
});
