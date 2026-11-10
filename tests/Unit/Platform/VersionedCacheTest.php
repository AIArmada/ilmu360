<?php

declare(strict_types=1);

use App\Platform\Cache\CacheVersionStore;
use App\Platform\Cache\VersionedCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

it('remembers a value under the current version until the version is bumped', function (): void {
    $versions = app(CacheVersionStore::class);
    $cache = app(VersionedCache::class);
    $versionKey = 'test:versioned-cache:version';
    $key = 'test:versioned-cache:value';
    $calls = 0;

    $resolver = static function () use (&$calls): string {
        $calls++;

        return 'value-'.$calls;
    };

    expect($cache->remember($versionKey, $key, 60, $resolver))->toBe('value-1')
        ->and($cache->remember($versionKey, $key, 60, $resolver))->toBe('value-1')
        ->and($calls)->toBe(1)
        ->and(Cache::has($key.':'.$versions->version($versionKey)))->toBeTrue();

    $versions->bump($versionKey);

    expect($cache->remember($versionKey, $key, 60, $resolver))->toBe('value-2')
        ->and($calls)->toBe(2);
});

it('keeps values in separate namespaces when version keys differ', function (): void {
    $cache = app(VersionedCache::class);

    $first = $cache->remember('test:versioned-cache:first-version', 'test:versioned-cache:shared', 60, static fn (): string => 'first');
    $second = $cache->remember('test:versioned-cache:second-version', 'test:versioned-cache:shared', 60, static fn (): string => 'second');

    expect($first)->toBe('first')
        ->and($second)->toBe('second');
});

it('passes the expiry to the underlying store', function (): void {
    $cache = app(VersionedCache::class);
    $version = app(CacheVersionStore::class)->version('test:versioned-cache:ttl-version');

    $cache->remember('test:versioned-cache:ttl-version', 'test:versioned-cache:ttl', 1, static fn (): string => 'expiring');

    expect(Cache::has('test:versioned-cache:ttl:'.$version))->toBeTrue();

    $this->travel(2)->seconds();

    expect(Cache::has('test:versioned-cache:ttl:'.$version))->toBeFalse();
});
