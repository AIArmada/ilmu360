<?php

declare(strict_types=1);

use App\Features\Persons\Actions\SyncPersonSearchProjectionAction;
use App\Features\Persons\Api\PersonSearchCache;
use App\Features\Persons\Persistence\PersonSearchCacheStore;
use App\Features\Persons\Services\PersonSearchService;
use App\Shared\Cache\DiscoveryCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Cache::flush();
});

it('binds the person public-search cache api to the directory cache store', function (): void {
    expect(app(PersonSearchCache::class))
        ->toBeInstanceOf(PersonSearchCacheStore::class);
});

it('shares one advancing ULID version key across both public person-search bump entrypoints', function (): void {
    $service = app(PersonSearchService::class);
    $discoveryCache = app(DiscoveryCache::class);
    $versionKey = (string) config('discovery.cache.versions.persons_search');
    $search = 'Shared Person Cache Version';

    $resultKey = static fn (string $version): string => sprintf(
        'person_search_public:%s:%s',
        md5((string) $service->normalizedSearch($search)),
        $version,
    );

    $initialVersion = $discoveryCache->version($versionKey);
    expect(Str::isUlid($initialVersion))->toBeTrue();

    Cache::put($resultKey($initialVersion), ['stale-person-id'], now()->addMinutes(10));

    expect($service->publicSearchIds($search))->toBe(['stale-person-id']);

    $service->bustPublicSearchCache();
    $bumpedOnce = $discoveryCache->version($versionKey);

    expect($bumpedOnce)->not->toBe($initialVersion)
        ->and(Str::isUlid($bumpedOnce))->toBeTrue()
        ->and($service->publicSearchIds($search))->toBeEmpty();

    Cache::put($resultKey($bumpedOnce), ['stale-person-id'], now()->addMinutes(10));

    expect($service->publicSearchIds($search))->toBe(['stale-person-id']);

    app(SyncPersonSearchProjectionAction::class)->bustPublicSearchCache();
    $bumpedTwice = $discoveryCache->version($versionKey);

    expect($bumpedTwice)->not->toBe($bumpedOnce)
        ->and(Str::isUlid($bumpedTwice))->toBeTrue()
        ->and($service->publicSearchIds($search))->toBeEmpty();
});
