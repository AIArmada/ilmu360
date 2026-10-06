<?php

declare(strict_types=1);

namespace Tests\Support\Prayer;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

class FailingPrayerCacheStore extends ArrayStore
{
    public function get($key, $default = null): mixed
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function put($key, $value, $seconds = null): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function many(array $keys): array
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function putMany(array $values, $seconds = null): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function increment($key, $value = 1): int|float
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function decrement($key, $value = 1): int|float
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function forget($key): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function flush(): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        throw new RuntimeException('Prayer cache store is down.');
    }
}
