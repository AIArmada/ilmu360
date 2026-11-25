<?php

declare(strict_types=1);

use App\Platform\Cache\ManagedDiscoveryCache;
use App\Shared\Cache\DiscoveryCache;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as RepositoryContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    config()->set('discovery.cache.enabled', true);
    config()->set('discovery.cache.store');
    config()->set('cache.default', 'array');
    app('cache')->setDefaultDriver('array');
    Cache::flush();
});

it('resolves ManagedDiscoveryCache as the scoped DiscoveryCache binding', function (): void {
    expect(app(DiscoveryCache::class))
        ->toBeInstanceOf(ManagedDiscoveryCache::class);
});

it('uses read-first version lookups with zero writes on warm hits', function (): void {
    $cache = app(DiscoveryCache::class);
    $key = 'test:discovery:read-first';

    $warmVersion = (string) Str::ulid();
    Cache::forever($key, $warmVersion);

    $spyStore = Mockery::mock(RepositoryContract::class);
    $spyStore->shouldReceive('get')->once()->with($key)->andReturn($warmVersion);
    $spyStore->shouldNotReceive('add');
    $spyStore->shouldNotReceive('forever');

    Cache::shouldReceive('store')->once()->andReturn($spyStore);

    expect($cache->version($key))->toBe($warmVersion);
});

it('generates and adds a ULID on cold version miss and updates on bump', function (): void {
    $cache = app(DiscoveryCache::class);
    $key = 'test:discovery:cold-miss';

    $initial = $cache->version($key);

    expect(Str::isUlid($initial))->toBeTrue()
        ->and(Cache::get($key))->toBe($initial)
        ->and($cache->version($key))->toBe($initial);

    $bumped = $cache->bump($key);

    expect($bumped)->not->toBe($initial)
        ->and(Str::isUlid($bumped))->toBeTrue()
        ->and($cache->version($key))->toBe($bumped)
        ->and(Cache::get($key))->toBe($bumped);
});

it('bypasses caching when DISCOVERY_CACHE_ENABLED is false', function (): void {
    config()->set('discovery.cache.enabled', false);

    $cache = app(DiscoveryCache::class);
    $calls = 0;

    $first = $cache->remember('test:discovery:disabled:v', 'test:discovery:disabled:k', 60, function () use (&$calls): string {
        $calls++;

        return 'call-'.$calls;
    });
    $second = $cache->remember('test:discovery:disabled:v', 'test:discovery:disabled:k', 60, function () use (&$calls): string {
        $calls++;

        return 'call-'.$calls;
    });

    expect($first)->toBe('call-1')
        ->and($second)->toBe('call-2')
        ->and($calls)->toBe(2)
        ->and(Cache::has('test:discovery:disabled:v'))->toBeFalse();
});

it('supports store override, rememberInt, forget, and persistent store memoization reset', function (): void {
    config()->set('discovery.cache.store', 'file');
    $fileStore = Cache::store('file');
    $fileStore->flush();

    $cache = app(DiscoveryCache::class);
    $versionKey = 'test:discovery:file:v';

    $v1 = $cache->version($versionKey);
    expect($fileStore->get($versionKey))->toBe($v1);

    // Underlying store cleared externally; persistent memo still holds $v1 until resetRequestMemo()
    $fileStore->forget($versionKey);
    expect($cache->version($versionKey))->toBe($v1);

    $cache->resetRequestMemo();
    $v2 = $cache->version($versionKey);
    expect($v2)->not->toBe($v1)
        ->and($fileStore->get($versionKey))->toBe($v2);

    // rememberInt coerces numeric strings
    $fileStore->put("test:discovery:int:{$v2}", '77', 60);
    expect($cache->rememberInt($versionKey, 'test:discovery:int', 60, static fn (): int => 0))->toBe(77);

    $cache->forget("test:discovery:int:{$v2}");
    expect($fileStore->has("test:discovery:int:{$v2}"))->toBeFalse();

    $fileStore->flush();
});

it('does not memoize versions on ArrayStore so Cache::flush() never leaves stale versions', function (): void {
    /** @var Repository $arrayRepo */
    $arrayRepo = Cache::store('array');
    expect($arrayRepo)->toBeInstanceOf(Repository::class);

    $cache = app(DiscoveryCache::class);
    $key = 'test:discovery:array-no-memo';

    $v1 = $cache->version($key);
    Cache::flush();
    $v2 = $cache->version($key);

    expect($v2)->not->toBe($v1);
});
