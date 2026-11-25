<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class ModerationQueueBadgeCacheStore
{
    private const string PENDING_BADGE_KEY = 'moderation_queue:pending_badge';

    private const int CACHE_TTL_SECONDS = 30;

    /**
     * @param  Closure(): int  $resolver
     */
    public function rememberPendingCount(Closure $resolver): int
    {
        $count = Cache::remember(
            self::PENDING_BADGE_KEY,
            self::CACHE_TTL_SECONDS,
            $resolver,
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function forgetPendingCount(): void
    {
        Cache::forget(self::PENDING_BADGE_KEY);
    }
}
