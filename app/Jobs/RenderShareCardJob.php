<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Event;
use App\Services\ShareCards\ShareCardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Pre-warm the default generated share card after an event write.
 *
 * Best-effort latency optimization only: the card route renders on a
 * cache miss and falls back to cover art, so a skipped or failed warm
 * never produces a dead image URL. Single attempt, no retries — a
 * failing render retries naturally on the next request.
 */
final class RenderShareCardJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $eventId,
    ) {}

    public function uniqueId(): string
    {
        return 'share-card-warm:'.$this->eventId;
    }

    public static function prewarmForEvent(mixed $eventId): void
    {
        if (! (bool) config('share-cards.prewarm_on_save', true)) {
            return;
        }

        if (! is_string($eventId) && ! is_int($eventId)) {
            return;
        }

        $eventId = (string) $eventId;

        if ($eventId === '') {
            return;
        }

        self::dispatch($eventId)->afterCommit();
    }

    public function handle(ShareCardService $shareCards): void
    {
        try {
            $event = Event::query()->whereKey($this->eventId)->first();

            if (! $event instanceof Event || ! $event->isPubliclyReachable()) {
                return;
            }

            $occurrence = $shareCards->resolveOccurrence($event, null);
            $locale = ShareCardService::DEFAULT_LOCALE;
            $displayTimezone = $shareCards->resolveDisplayTimezone($event);

            $shareCards->renderForRequest($event, $occurrence, $locale, $displayTimezone);
        } catch (Throwable $exception) {
            // Best-effort means best-effort: a warm must never fail the
            // write that triggered it (sync queue runs this inline). The
            // card route renders on demand with honest fallback, so the
            // miss is self-healing. Still reported, never silent.
            report($exception);
        }
    }
}
