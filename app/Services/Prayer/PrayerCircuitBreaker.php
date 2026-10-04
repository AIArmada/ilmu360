<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-provider circuit breaker for live prayer-times fetches.
 *
 * After `threshold` consecutive failures a provider opens for `cooldown`
 * seconds and live attempts short-circuit to ProviderUnavailable, so one
 * down upstream cannot stall every refresh job and preview.
 *
 * Consecutive-failure lifecycle: the counter accumulates until success
 * resets it (or a completed cooldown starts a fresh cycle), so a slow
 * trickle of failures still reaches the threshold — a cooldown-sized
 * window from the first failure would silently forgive failures that
 * no success interrupted. The 30-day seed TTL is only a safety valve
 * against unbounded key growth.
 *
 * Every lifecycle mutation — counting, opening, resetting, clearing —
 * runs under one per-provider lock on every store, including atomic
 * ones: a reset is a read (expired marker?) followed by two forgets,
 * and no store makes that sequence atomic. The reset re-reads the
 * marker inside the lock and only forgets when it still shows the
 * expired deadline the caller observed, so a stale reset can never
 * erase a cycle another worker already reopened. A failure landing
 * on an elapsed cooldown performs the reset itself under the same
 * lock, so deferred resets never trip on stale history either.
 *
 * A contender that cannot take the lock never mutates: allow() lets
 * the elapsed-cooldown request through and leaves the reset to the
 * lock holder, while recordFailure()/recordSuccess() drop their
 * signal. Dropped failures open the breaker later under extreme
 * contention; a dropped success retains the count and re-opens
 * sooner. Availability first, mutation safety always.
 *
 * Fails open when the cache store itself is down: a dead cache must
 * never block a live refresh that could still succeed.
 */
final class PrayerCircuitBreaker
{
    public function allow(string $providerKey): bool
    {
        try {
            $openedUntil = $this->cache()->get($this->openKey($providerKey));

            if ($this->isOpen($openedUntil)) {
                return false;
            }

            if ($openedUntil === null) {
                return true;
            }

            try {
                return (bool) $this->underLifecycleLock($providerKey, function () use ($providerKey, $openedUntil): bool {
                    $fresh = $this->cache()->get($this->openKey($providerKey));

                    if ($fresh !== $openedUntil) {
                        // Another worker reset or reopened while this
                        // read was stale: decide on fresh state and
                        // mutate nothing.
                        return ! $this->isOpen($fresh);
                    }

                    $this->cache()->forget($this->openKey($providerKey));
                    $this->cache()->forget($this->failuresKey($providerKey));

                    return true;
                });
            } catch (LockTimeoutException) {
                // Cooldown elapsed so the request proceeds, but the
                // reset stays with the lock holder: resetting blind
                // here could erase a cycle another worker reopened.
                return true;
            }
        } catch (Throwable) {
            return true;
        }
    }

    public function recordSuccess(string $providerKey): void
    {
        try {
            $this->underLifecycleLock($providerKey, function () use ($providerKey): void {
                $this->cache()->forget($this->failuresKey($providerKey));
                $this->cache()->forget($this->openKey($providerKey));
            });
        } catch (LockTimeoutException) {
            // Contended: keep the stale count rather than clear outside
            // the lock. A retained count re-opens sooner — the
            // conservative direction for one success amid failures.
        } catch (Throwable) {
            // Breaker state is best-effort; the fetch already succeeded.
        }
    }

    public function recordFailure(string $providerKey): void
    {
        try {
            $this->underLifecycleLock($providerKey, function () use ($providerKey): void {
                $cache = $this->cache();
                $openedUntil = $cache->get($this->openKey($providerKey));

                if ($openedUntil !== null && ! $this->isOpen($openedUntil)) {
                    // A failure landing on an elapsed cooldown starts the
                    // fresh cycle itself, so the count never trips on
                    // stale history when allow()'s reset was deferred.
                    $cache->forget($this->openKey($providerKey));
                    $cache->forget($this->failuresKey($providerKey));
                }

                $key = $this->failuresKey($providerKey);
                $failures = $cache->increment($key);

                if ($failures === false) {
                    // Database/memcached increments cannot create: one
                    // long-lived store-level seed, then count. The
                    // explicit TTL keeps Repository::add on the atomic
                    // store path instead of its get+put fallback.
                    $cache->add($key, 0, CarbonInterval::days(30));
                    $failures = $cache->increment($key);
                }

                $failures = (int) $failures;

                if ($failures >= $this->threshold()) {
                    // The deadline outlives the cooldown: allow() resets
                    // the failure cycle only while it can still see the
                    // elapsed marker. A cooldown-sized TTL would expire
                    // the marker at the same instant blocking ends, so
                    // the reset branch would never run and the first
                    // post-cooldown failure would re-open on retained
                    // history.
                    $cache->put($this->openKey($providerKey), Carbon::now()->getTimestamp() + $this->cooldown(), CarbonInterval::days(30));

                    Log::warning('Prayer provider circuit opened after consecutive failures.', [
                        'provider' => $providerKey,
                        'failures' => $failures,
                        'cooldown' => $this->cooldown(),
                    ]);
                }
            });
        } catch (LockTimeoutException) {
            // Contended: drop this failure signal rather than count it
            // outside the lifecycle lock. The breaker opens later under
            // extreme contention; it never corrupts a sibling cycle.
        } catch (Throwable) {
            // Breaker state is best-effort; the caller already degrades.
        }
    }

    /**
     * @return array{failures: int, open: bool}
     */
    public function state(string $providerKey): array
    {
        try {
            $openedUntil = $this->cache()->get($this->openKey($providerKey));

            return [
                'failures' => (int) $this->cache()->get($this->failuresKey($providerKey), 0),
                'open' => $this->isOpen($openedUntil),
            ];
        } catch (Throwable) {
            return ['failures' => 0, 'open' => false];
        }
    }

    private function isOpen(mixed $openedUntil): bool
    {
        return is_numeric($openedUntil) && (int) $openedUntil > Carbon::now()->getTimestamp();
    }

    /**
     * Runs a lifecycle transition under the per-provider lock. Stores
     * without lock support (custom drivers only — every framework store
     * implements LockProvider) run the transition directly: degraded
     * counting beats a silently dead breaker.
     */
    private function underLifecycleLock(string $providerKey, callable $transition): mixed
    {
        $store = $this->cache()->getStore();

        if (! $store instanceof LockProvider) {
            return $transition();
        }

        return $store->lock($this->prefix().":breaker:{$providerKey}:lifecycle", 10)->block(5, $transition);
    }

    public function threshold(): int
    {
        return max(1, (int) config('prayer.breaker.threshold', 5));
    }

    public function cooldown(): int
    {
        return max(1, (int) config('prayer.breaker.cooldown', 300));
    }

    private function failuresKey(string $providerKey): string
    {
        return $this->prefix().":breaker:{$providerKey}:failures";
    }

    private function openKey(string $providerKey): string
    {
        return $this->prefix().":breaker:{$providerKey}:open";
    }

    private function prefix(): string
    {
        return (string) config('prayer.cache.prefix', 'prayer:v3');
    }

    private function cache(): CacheRepository
    {
        /** @var string|null $store */
        $store = config('prayer.cache.store');

        return Cache::store($store);
    }
}
