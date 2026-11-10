<?php

declare(strict_types=1);

use App\Platform\Cache\CacheVersionStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('returns a stable stored version until the key is bumped', function (): void {
    $store = app(CacheVersionStore::class);
    $key = 'test:cache-version:stable';

    $version = $store->version($key);

    expect($version)->not->toBe('')
        ->and(Str::isUlid($version))->toBeTrue()
        ->and($store->version($key))->toBe($version)
        ->and(Cache::get($key))->toBe($version);
});

it('stores a fresh version when the key is bumped', function (): void {
    $store = app(CacheVersionStore::class);
    $key = 'test:cache-version:bumped';

    $original = $store->version($key);
    $store->bump($key);
    $bumped = $store->version($key);

    expect($bumped)->not->toBe($original)
        ->and($store->version($key))->toBe($bumped)
        ->and(Cache::get($key))->toBe($bumped);
});
