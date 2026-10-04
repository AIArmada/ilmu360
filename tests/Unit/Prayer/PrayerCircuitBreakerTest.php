<?php

use App\Data\Prayer\PrayerQuery;
use App\Services\Prayer\AladhanPrayerProvider;
use App\Services\Prayer\PrayerCircuitBreaker;
use App\Services\Prayer\ProviderUnavailable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
    config(['prayer.breaker.threshold' => 2, 'prayer.breaker.cooldown' => 60]);
});

it('opens after consecutive failures and short-circuits without HTTP', function () {
    Http::fake(['api.aladhan.com/*' => Http::response([], 500)]);
    $provider = app(AladhanPrayerProvider::class);
    $query = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8);

    try {
        $provider->dailyPrayers($query);
        $this->fail('Expected ProviderUnavailable.');
    } catch (ProviderUnavailable) {
    }

    try {
        $provider->dailyPrayers($query);
        $this->fail('Expected ProviderUnavailable.');
    } catch (ProviderUnavailable) {
    }

    expect(app(PrayerCircuitBreaker::class)->state('aladhan'))->toBe(['failures' => 2, 'open' => true]);

    try {
        $provider->dailyPrayers($query);
        $this->fail('Expected an open-circuit ProviderUnavailable.');
    } catch (ProviderUnavailable $exception) {
        expect($exception->getMessage())->toBe('Aladhan circuit is open.');
    }

    Http::assertSentCount(2);
});

it('counts auth failures toward the open circuit', function (int $status) {
    Http::fake(['api.aladhan.com/*' => Http::response([], $status)]);
    $provider = app(AladhanPrayerProvider::class);
    $query = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8);

    try {
        $provider->dailyPrayers($query);
        $this->fail("Expected ProviderUnavailable for HTTP {$status}.");
    } catch (ProviderUnavailable) {
    }

    try {
        $provider->dailyPrayers($query);
        $this->fail("Expected ProviderUnavailable for HTTP {$status}.");
    } catch (ProviderUnavailable) {
    }

    expect(app(PrayerCircuitBreaker::class)->state('aladhan'))->toBe(['failures' => 2, 'open' => true]);
})->with([401, 403]);

it('never trips the breaker on unpublished 404s', function () {
    Http::fake(['api.aladhan.com/*' => Http::response([], 404)]);
    $provider = app(AladhanPrayerProvider::class);
    $query = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8);

    try {
        $provider->dailyPrayers($query);
        $this->fail('Expected ProviderUnavailable for HTTP 404.');
    } catch (ProviderUnavailable) {
    }

    expect(app(PrayerCircuitBreaker::class)->state('aladhan'))->toBe(['failures' => 0, 'open' => false]);
});

it('resets the count on success', function () {
    $breaker = app(PrayerCircuitBreaker::class);
    $breaker->recordFailure('ummah');

    expect($breaker->state('ummah'))->toBe(['failures' => 1, 'open' => false]);

    $breaker->recordSuccess('ummah');

    expect($breaker->state('ummah'))->toBe(['failures' => 0, 'open' => false]);
});

it('fails open when the cache store is down', function () {
    config(['prayer.cache.store' => 'missing-store']);

    expect(app(PrayerCircuitBreaker::class)->allow('jakim'))->toBeTrue()
        ->and(app(PrayerCircuitBreaker::class)->state('jakim'))->toBe(['failures' => 0, 'open' => false]);

    // Recording must not throw either.
    app(PrayerCircuitBreaker::class)->recordFailure('jakim');
    app(PrayerCircuitBreaker::class)->recordSuccess('jakim');

    expect(true)->toBeTrue();
});

it('opens from an empty database store', function () {
    // Unit scope runs without migrations; create the cache tables the
    // lifecycle lock needs, mirroring the shipped cache migration.
    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->bigInteger('expiration')->index();
    });
    Schema::create('cache_locks', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->string('owner');
        $table->bigInteger('expiration')->index();
    });

    config(['prayer.cache.store' => 'database']);
    Cache::store('database')->flush();

    $breaker = app(PrayerCircuitBreaker::class);

    $breaker->recordFailure('aladhan');
    $breaker->recordFailure('aladhan');

    // DatabaseStore::increment on a missing key returns false; the
    // repair seed lets the count start at one so the circuit opens.
    expect($breaker->state('aladhan'))->toBe(['failures' => 2, 'open' => true])
        ->and($breaker->allow('aladhan'))->toBeFalse();

    config(['prayer.cache.store' => null]);
});

it('writes the failure counter with atomic primitives only, so interleaved failures cannot erase each other', function () {
    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->bigInteger('expiration')->index();
    });
    Schema::create('cache_locks', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->string('owner');
        $table->bigInteger('expiration')->index();
    });

    // The database store lets the test observe every Repository::put —
    // the exact operation that lets a post-increment reset erase a
    // concurrent worker's increment and miss the opening threshold. The
    // counter must never see one: the persistent seed for stores whose
    // increment cannot create goes through add(), never put().
    $spy = new class(Cache::store('database')->getStore()) extends Repository
    {
        /** @var list<array{0: string, 1: mixed}> */
        public array $puts = [];

        public function put($key, $value, $ttl = null)
        {
            $this->puts[] = [(string) $key, $value];

            return parent::put($key, $value, $ttl);
        }
    };

    Cache::extend('spy-prayer-store', fn () => $spy);
    config([
        'cache.stores.spy-prayer-store' => ['driver' => 'spy-prayer-store'],
        'prayer.cache.store' => 'spy-prayer-store',
    ]);
    Cache::store('spy-prayer-store')->flush();

    $breaker = app(PrayerCircuitBreaker::class);
    $breaker->recordFailure('aladhan');
    $breaker->recordFailure('aladhan');

    expect($breaker->state('aladhan'))->toBe(['failures' => 2, 'open' => true]);

    $counterPuts = array_values(array_filter(
        $spy->puts,
        static fn (array $put): bool => str_ends_with($put[0], ':failures'),
    ));

    // The open key is still put at the threshold; the counter itself must
    // never see any put at all.
    expect($counterPuts)->toBe([])
        ->and(array_column($spy->puts, 0))->toContain('prayer:v3:breaker:aladhan:open');

    config(['prayer.cache.store' => null]);
});

it('accumulates a slow trickle of consecutive failures to the threshold', function () {
    config(['prayer.breaker.threshold' => 5, 'prayer.breaker.cooldown' => 60]);

    $breaker = app(PrayerCircuitBreaker::class);
    $start = Carbon::now('UTC');

    // Failures 150s apart with no success between: a fixed window from
    // the first failure would expire the counter before the fifth lands.
    foreach ([0, 150, 300, 450, 600] as $index => $seconds) {
        Carbon::setTestNow($start->copy()->addSeconds($seconds));
        $breaker->recordFailure('ummah');

        expect($breaker->state('ummah')['failures'])->toBe($index + 1);
    }

    expect($breaker->state('ummah'))->toBe(['failures' => 5, 'open' => true])
        ->and($breaker->allow('ummah'))->toBeFalse();

    Carbon::setTestNow();
});

it('starts a fresh failure cycle once the cooldown elapses', function () {
    config(['prayer.breaker.threshold' => 3, 'prayer.breaker.cooldown' => 60]);

    $breaker = app(PrayerCircuitBreaker::class);
    $start = Carbon::now('UTC');

    $breaker->recordFailure('aladhan');
    $breaker->recordFailure('aladhan');
    $breaker->recordFailure('aladhan');

    expect($breaker->allow('aladhan'))->toBeFalse();

    // Past the cooldown but inside marker retention: the elapsed
    // deadline is still visible, so the cycle resets — twice, to pin
    // that concurrent cooldown expiries are idempotent.
    Carbon::setTestNow($start->copy()->addSeconds(61));

    expect($breaker->allow('aladhan'))->toBeTrue()
        ->and($breaker->allow('aladhan'))->toBeTrue()
        ->and($breaker->state('aladhan'))->toBe(['failures' => 0, 'open' => false]);

    // One fresh failure counts from one instead of re-opening on
    // retained history.
    $breaker->recordFailure('aladhan');

    expect($breaker->state('aladhan'))->toBe(['failures' => 1, 'open' => false])
        ->and($breaker->allow('aladhan'))->toBeTrue();

    Carbon::setTestNow();
});

it('serializes failure counts under a lock on the file store', function () {
    // True cross-process interleaving is not reproducible in-process;
    // the spy pins that file counts take the lifecycle lock while
    // sequential counting still reaches the threshold and opens.
    $files = new Filesystem;
    $path = sys_get_temp_dir().'/prayer-breaker-file-'.uniqid();

    $spy = new class($files, $path) extends FileStore
    {
        /** @var list<string> */
        public array $locks = [];

        public function lock($name, $seconds = 0, $owner = null)
        {
            $this->locks[] = (string) $name;

            return parent::lock($name, $seconds, $owner);
        }
    };

    Cache::extend('file-prayer-store', fn () => new Repository($spy));
    config([
        'cache.stores.file-prayer-store' => ['driver' => 'file-prayer-store'],
        'prayer.cache.store' => 'file-prayer-store',
    ]);

    $breaker = app(PrayerCircuitBreaker::class);
    $breaker->recordFailure('aladhan');
    $breaker->recordFailure('aladhan');

    expect($breaker->state('aladhan'))->toBe(['failures' => 2, 'open' => true])
        ->and($spy->locks)->toContain('prayer:v3:breaker:aladhan:lifecycle');

    config(['prayer.cache.store' => null]);
    $files->deleteDirectory($path);
});

it('serializes lifecycle transitions under one lock on atomic stores too', function () {
    // The reset is read-then-forget, which no store makes atomic: even
    // atomic counters serialize counting/opening/resetting under the
    // lifecycle lock so a stale reset cannot erase a reopened cycle.
    $store = new class extends ArrayStore
    {
        /** @var list<string> */
        public array $lockNames = [];

        public function lock($name, $seconds = 0, $owner = null)
        {
            $this->lockNames[] = (string) $name;

            return parent::lock($name, $seconds, $owner);
        }
    };

    Cache::extend('array-prayer-store', fn () => new Repository($store));
    config([
        'cache.stores.array-prayer-store' => ['driver' => 'array-prayer-store'],
        'prayer.cache.store' => 'array-prayer-store',
    ]);

    $breaker = app(PrayerCircuitBreaker::class);
    $breaker->recordFailure('aladhan');
    $breaker->recordFailure('aladhan');

    expect($breaker->state('aladhan'))->toBe(['failures' => 2, 'open' => true])
        ->and($store->lockNames)->toContain('prayer:v3:breaker:aladhan:lifecycle');

    config(['prayer.cache.store' => null]);
});

it('checks allow without locks on the fast path', function () {
    $store = new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null)
        {
            throw new RuntimeException('allow fast paths must not take breaker locks');
        }
    };

    Cache::extend('fastpath-prayer-store', fn () => new Repository($store));
    config([
        'cache.stores.fastpath-prayer-store' => ['driver' => 'fastpath-prayer-store'],
        'prayer.cache.store' => 'fastpath-prayer-store',
    ]);

    $breaker = app(PrayerCircuitBreaker::class);

    expect($breaker->allow('aladhan'))->toBeTrue();

    Cache::store('fastpath-prayer-store')->put(
        'prayer:v3:breaker:aladhan:open',
        Carbon::now('UTC')->getTimestamp() + 60,
        3600,
    );

    expect($breaker->allow('aladhan'))->toBeFalse();

    config(['prayer.cache.store' => null]);
});

it('starts a fresh failure cycle when a failure lands on an elapsed cooldown', function () {
    config(['prayer.breaker.threshold' => 3, 'prayer.breaker.cooldown' => 60]);

    $breaker = app(PrayerCircuitBreaker::class);
    $start = Carbon::now('UTC');

    $breaker->recordFailure('aladhan');
    $breaker->recordFailure('aladhan');
    $breaker->recordFailure('aladhan');

    expect($breaker->allow('aladhan'))->toBeFalse();

    // The reset never ran (no allow() call): the first post-cooldown
    // failure performs it under the lifecycle lock instead of
    // tripping on retained history.
    Carbon::setTestNow($start->copy()->addSeconds(61));
    $breaker->recordFailure('aladhan');

    expect($breaker->state('aladhan'))->toBe(['failures' => 1, 'open' => false])
        ->and($breaker->allow('aladhan'))->toBeTrue();

    Carbon::setTestNow();
});

it('defers lifecycle transitions without mutating under lock contention', function () {
    // True cross-process interleaving is not reproducible in-process;
    // the controllable lock stands in for a worker holding the
    // lifecycle lock: every transition must defer without mutating.
    $store = new class extends ArrayStore
    {
        public bool $contended = false;

        public function lock($name, $seconds = 0, $owner = null)
        {
            if (! $this->contended) {
                return parent::lock($name, $seconds, $owner);
            }

            return new class((string) $name, (int) $seconds) extends Lock
            {
                public function acquire()
                {
                    return false;
                }

                public function release()
                {
                    return true;
                }

                public function forceRelease()
                {
                    return true;
                }

                protected function getCurrentOwner()
                {
                    return null;
                }

                public function block($seconds, $callback = null)
                {
                    throw new LockTimeoutException;
                }
            };
        }
    };

    Cache::extend('contention-prayer-store', fn () => new Repository($store));
    config([
        'cache.stores.contention-prayer-store' => ['driver' => 'contention-prayer-store'],
        'prayer.cache.store' => 'contention-prayer-store',
        'prayer.breaker.threshold' => 2,
        'prayer.breaker.cooldown' => 60,
    ]);

    $breaker = app(PrayerCircuitBreaker::class);
    $start = Carbon::now('UTC');

    $breaker->recordFailure('aladhan');
    $breaker->recordFailure('aladhan');

    expect($breaker->state('aladhan'))->toBe(['failures' => 2, 'open' => true]);

    Carbon::setTestNow($start->copy()->addSeconds(61));

    $store->contended = true;

    // Elapsed cooldown allows the request but defers the reset: both
    // keys stay untouched for the lock holder.
    expect($breaker->allow('aladhan'))->toBeTrue()
        ->and($breaker->state('aladhan'))->toBe(['failures' => 2, 'open' => false]);

    // A contended failure drops its signal instead of counting outside
    // the lifecycle lock.
    $breaker->recordFailure('aladhan');

    expect($breaker->state('aladhan'))->toBe(['failures' => 2, 'open' => false]);

    $store->contended = false;

    // The next holder performs the deferred reset, then counts fresh.
    expect($breaker->allow('aladhan'))->toBeTrue()
        ->and($breaker->state('aladhan'))->toBe(['failures' => 0, 'open' => false]);

    $breaker->recordFailure('aladhan');

    expect($breaker->state('aladhan'))->toBe(['failures' => 1, 'open' => false]);

    Carbon::setTestNow();
    config(['prayer.cache.store' => null]);
});
