<?php

declare(strict_types=1);

namespace App\Platform\Cache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers values under a version-specific key so bumping the shared version
 * invalidates the whole namespace without deleting individual entries.
 */
final readonly class VersionedCache
{
    public function __construct(private CacheVersionStore $versions) {}

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $resolver
     * @return TValue
     */
    public function remember(
        string $versionKey,
        string $key,
        DateInterval|DateTimeInterface|int|null $ttl,
        Closure $resolver,
    ): mixed {
        /** @var TValue $value */
        $value = Cache::remember($this->key($versionKey, $key), $ttl, $resolver);

        return $value;
    }

    private function key(string $versionKey, string $key): string
    {
        return $key.':'.$this->versions->version($versionKey);
    }
}
