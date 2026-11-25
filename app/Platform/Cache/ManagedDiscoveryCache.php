<?php

declare(strict_types=1);

namespace App\Platform\Cache;

use App\Shared\Cache\DiscoveryCache;
use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Request-scoped, read-first versioned discovery cache backed by a configurable store.
 */
final class ManagedDiscoveryCache implements DiscoveryCache
{
    /** @var array<string, string> */
    private array $versionMemo = [];

    public function version(string $versionKey): string
    {
        $store = $this->store();
        $memoize = $this->shouldMemoize($store);

        if ($memoize && isset($this->versionMemo[$versionKey])) {
            return $this->versionMemo[$versionKey];
        }

        $existing = $store->get($versionKey);

        if (is_string($existing) && $existing !== '') {
            if ($memoize) {
                $this->versionMemo[$versionKey] = $existing;
            }

            return $existing;
        }

        $candidate = (string) Str::ulid();

        if ($store->add($versionKey, $candidate)) {
            if ($memoize) {
                $this->versionMemo[$versionKey] = $candidate;
            }

            return $candidate;
        }

        $raced = $store->get($versionKey);
        $resolved = is_string($raced) && $raced !== '' ? $raced : $candidate;

        if ($memoize) {
            $this->versionMemo[$versionKey] = $resolved;
        }

        return $resolved;
    }

    public function bump(string $versionKey): string
    {
        $store = $this->store();
        $version = (string) Str::ulid();
        $store->forever($versionKey, $version);

        if ($this->shouldMemoize($store)) {
            $this->versionMemo[$versionKey] = $version;
        }

        return $version;
    }

    public function remember(string $versionKey, string $key, int $ttlSeconds, Closure $resolver): mixed
    {
        if (! (bool) config('discovery.cache.enabled', true)) {
            return $resolver();
        }

        $version = $this->version($versionKey);
        $store = $this->store();

        /** @var mixed $value */
        $value = $store->remember("{$key}:{$version}", $ttlSeconds, $resolver);

        return $value;
    }

    public function rememberInt(string $versionKey, string $key, int $ttlSeconds, Closure $resolver): int
    {
        $value = $this->remember($versionKey, $key, $ttlSeconds, $resolver);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function forget(string $key): void
    {
        $this->store()->forget($key);
    }

    public function resetRequestMemo(): void
    {
        $this->versionMemo = [];
    }

    private function store(): Repository
    {
        $storeName = config('discovery.cache.store');

        return is_string($storeName) && $storeName !== ''
            ? Cache::store($storeName)
            : Cache::store();
    }

    private function shouldMemoize(Repository $store): bool
    {
        if (! $store instanceof CacheRepository) {
            return false;
        }

        return ! ($store->getStore() instanceof ArrayStore);
    }
}
