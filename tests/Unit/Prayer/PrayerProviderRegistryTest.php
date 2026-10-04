<?php

use App\Contracts\NullPrayerTimesProvider;
use App\Contracts\PrayerTimesProvider;
use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Services\Prayer\PrayerProviderRegistry;
use App\Services\Prayer\ProviderUnavailable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

class StubPrayerProviderAlpha implements PrayerTimesProvider
{
    public function key(): string
    {
        return 'alpha';
    }

    public function supports(string $countryCode): bool
    {
        return true;
    }

    public function requiresZone(): bool
    {
        return false;
    }

    public function cacheIdentitySegment(PrayerQuery $query): string
    {
        return 'alpha';
    }

    public function isSourceCurrent(string $source, string $countryCode): bool
    {
        return true;
    }

    public function calcFingerprint(PrayerQuery $query): ?string
    {
        return null;
    }

    public function dailyPrayers(PrayerQuery $query): PrayerTimesDTO
    {
        throw new ProviderUnavailable('stub');
    }
}

class StubPrayerProviderBeta implements PrayerTimesProvider
{
    public function key(): string
    {
        return 'beta';
    }

    public function supports(string $countryCode): bool
    {
        return $countryCode !== 'MY';
    }

    public function requiresZone(): bool
    {
        return false;
    }

    public function cacheIdentitySegment(PrayerQuery $query): string
    {
        return 'beta';
    }

    public function isSourceCurrent(string $source, string $countryCode): bool
    {
        return true;
    }

    public function calcFingerprint(PrayerQuery $query): ?string
    {
        return null;
    }

    public function dailyPrayers(PrayerQuery $query): PrayerTimesDTO
    {
        throw new ProviderUnavailable('stub');
    }
}

beforeEach(function () {
    config()->set('prayer.provider_map', [
        'alpha' => StubPrayerProviderAlpha::class,
        'beta' => StubPrayerProviderBeta::class,
        'null' => NullPrayerTimesProvider::class,
    ]);
    config()->set('prayer.providers', ['MY' => ['alpha', 'beta', 'null', 'ghost']]);
    config()->set('prayer.providers_default', ['beta']);
});

it('returns configured providers in order, skipping unsupported and unknown keys', function () {
    $providers = app(PrayerProviderRegistry::class)->orderedFor('MY');

    expect($providers)->toHaveCount(1)
        ->and($providers[0])->toBeInstanceOf(StubPrayerProviderAlpha::class);
});

it('falls back to the default chain for unmapped countries', function () {
    $providers = app(PrayerProviderRegistry::class)->orderedFor('ID');

    expect($providers)->toHaveCount(1)
        ->and($providers[0])->toBeInstanceOf(StubPrayerProviderBeta::class);
});

it('never serves times from the null provider', function () {
    $provider = new NullPrayerTimesProvider;

    expect($provider->supports('MY'))->toBeFalse();

    $provider->dailyPrayers(new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur'));
})->throws(ProviderUnavailable::class);

it('registers interleaved country-index entries without loss', function () {
    $repository = Cache::store(config('prayer.cache.store'));
    $key = config('prayer.cache.prefix', 'prayer:v3').':metric:missing-mapping:countries';

    // First registrations for two countries are both enumerable.
    app(PrayerProviderRegistry::class)->orderedFor('XX');
    app(PrayerProviderRegistry::class)->orderedFor('YY');

    expect($repository->get($key))->toBe(['XX', 'YY']);

    // A contended update skips instead of clobbering the holder's entry.
    $repository->flush();
    $lock = $repository->getStore()->lock($key.':lock', 10);

    expect($lock->acquire())->toBeTrue();

    app(PrayerProviderRegistry::class)->orderedFor('ZZ');

    expect($repository->get($key, []))->toBe([]);

    $lock->release();
    app(PrayerProviderRegistry::class)->orderedFor('ZZ');

    expect($repository->get($key))->toBe(['ZZ']);
});

it('serializes the missing-mapping counter with the country index', function () {
    $repository = Cache::store(config('prayer.cache.store'));
    $prefix = config('prayer.cache.prefix', 'prayer:v3');
    $key = "{$prefix}:metric:missing-mapping:countries";
    $counter = "{$prefix}:metric:missing-mapping:ZZ:".gmdate('Y-m-d');

    $lock = $repository->getStore()->lock($key.':lock', 10);

    expect($lock->acquire())->toBeTrue();

    app(PrayerProviderRegistry::class)->orderedFor('ZZ');

    // Contended: the counter stays untouched with the index — counting
    // outside the lock would lose simultaneous FileStore records.
    expect($repository->get($counter))->toBeNull()
        ->and($repository->get($key, []))->toBe([]);

    $lock->release();

    app(PrayerProviderRegistry::class)->orderedFor('ZZ');
    app(PrayerProviderRegistry::class)->orderedFor('ZZ');

    expect($repository->get($counter))->toBe(2)
        ->and($repository->get($key))->toBe(['ZZ']);
});
