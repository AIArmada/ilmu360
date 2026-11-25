<?php

declare(strict_types=1);

namespace App\Shared\Cache;

use Closure;

interface DiscoveryCache
{
    public function version(string $versionKey): string;

    public function bump(string $versionKey): string;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $resolver
     * @return TValue
     */
    public function remember(string $versionKey, string $key, int $ttlSeconds, Closure $resolver): mixed;

    /**
     * @param  Closure(): int  $resolver
     */
    public function rememberInt(string $versionKey, string $key, int $ttlSeconds, Closure $resolver): int;

    public function forget(string $key): void;

    public function resetRequestMemo(): void;
}
