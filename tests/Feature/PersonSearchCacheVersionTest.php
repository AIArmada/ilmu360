<?php

declare(strict_types=1);

use App\Features\Directory\Api\PersonSearchCacheVersion;
use App\Features\Directory\Persistence\PersonSearchCache;
use App\Support\Search\PersonSearchService;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

it('binds the person public-search version api to the directory cache', function (): void {
    expect(app(PersonSearchCacheVersion::class))
        ->toBeInstanceOf(PersonSearchCache::class)
        ->and(app(PersonSearchCacheVersion::class)->version())->toBe(2);
});

it('shares one advancing version key across both public person-search bump entrypoints', function (): void {
    $service = app(PersonSearchService::class);
    $cacheVersion = app(PersonSearchCacheVersion::class);
    $search = 'Shared Person Cache Version';

    $resultKey = static fn (int $version): string => sprintf(
        'person_search_public:%d:%s',
        $version,
        md5((string) $service->normalizedSearch($search)),
    );

    expect($cacheVersion->version())->toBe(2);

    Cache::put($resultKey(2), ['stale-person-id'], now()->addMinutes(10));

    expect($service->publicSearchIds($search))->toBe(['stale-person-id']);

    $service->bustPublicSearchCache();

    expect(Cache::get('person_search_public_version_v2'))->toBe(3)
        ->and($cacheVersion->version())->toBe(3)
        ->and($service->publicSearchIds($search))->toBe([]);

    Cache::put($resultKey(3), ['stale-person-id'], now()->addMinutes(10));

    expect($service->publicSearchIds($search))->toBe(['stale-person-id']);

    app(PersonSearchCache::class)->bump();

    expect(Cache::get('person_search_public_version_v2'))->toBe(4)
        ->and($cacheVersion->version())->toBe(4)
        ->and($service->publicSearchIds($search))->toBe([]);
});
