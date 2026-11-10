<?php

declare(strict_types=1);

namespace App\Platform\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stores opaque cache-busting versions so a namespace can invalidate all of
 * its derived entries without flushing the shared cache store.
 */
final class CacheVersionStore
{
    public function version(string $key): string
    {
        /** @var string $version */
        $version = Cache::rememberForever($key, static fn (): string => (string) Str::ulid());

        return $version;
    }

    public function bump(string $key): void
    {
        Cache::forever($key, (string) Str::ulid());
    }
}
