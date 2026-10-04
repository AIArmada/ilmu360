<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Prayer\JakimZoneResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves GPS coordinates to a JAKIM zone and memos the result.
 *
 * Dispatched on GPS-memo misses; the memo improves future submissions and
 * never the current one. Full-precision inputs always (V2.3).
 *
 * Deliberately not ShouldBeUnique (see RefreshPrayerTimes): the memo check
 * at handle start is the dedup, and a duplicate run costs one geocode.
 *
 * Retries are explicit self-redispatches, never release(): the dispatch
 * site uses dispatchAfterResponse, which runs this job on the sync
 * connection even when Redis is configured — and the sync driver drops
 * releases silently. The fresh attempt honors the configured
 * connection (delayed async on Redis; bounded inline nesting on sync).
 */
class ResolveGpsZone implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    private const MAX_ATTEMPTS = 3;

    private const RETRY_DELAY_MINUTES = 5;

    public function __construct(
        public float $latitude,
        public float $longitude,
        public int $attempt = 1,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(HttpFactory $http, JakimZoneResolver $zones): void
    {
        if ($zones->memoizedZone($this->latitude, $this->longitude) !== null) {
            return;
        }

        $base = rtrim((string) config('prayer.jakim.base_url', 'https://api.waktusolat.app'), '/');
        $timeout = (int) config('prayer.http_timeout', 3);

        try {
            $response = $http->timeout($timeout)->acceptJson()->get("{$base}/zones/{$this->latitude}/{$this->longitude}");
        } catch (Throwable $exception) {
            $this->scheduleRetry('GPS zone lookup failed; retry scheduled.', ['error' => $exception->getMessage()]);

            return;
        }

        if (! $response->successful()) {
            $this->scheduleRetry('GPS zone lookup returned an error status; retry scheduled.', ['status' => $response->status()]);

            return;
        }

        $rawZone = $response->json('zone');

        if (! is_string($rawZone)) {
            // A successful response with a non-string zone (e.g. {"zone":[]})
            // is unusable. Stop deterministically like an unknown zone: the
            // next lookup re-triggers resolution anyway, and throwing here
            // would abort the prayer warmers queued behind this job in the
            // after-response termination loop.
            Log::warning('GPS zone lookup returned a malformed zone.', ['zone' => $rawZone]);

            return;
        }

        $zone = strtoupper($rawZone);

        if ($zone === '' || ! $zones->isKnownZone($zone)) {
            // Deterministic: retrying a well-formed unknown zone cannot
            // help, and the next lookup re-triggers resolution anyway.
            Log::warning('GPS zone lookup returned an unknown zone.', ['zone' => $zone]);

            return;
        }

        $zones->rememberZone($this->latitude, $this->longitude, $zone);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scheduleRetry(string $message, array $context): void
    {
        if ($this->attempt >= self::MAX_ATTEMPTS) {
            Log::warning('GPS zone lookup exhausted retries; the next lookup will re-trigger.', $context);

            return;
        }

        Log::info($message, $context + ['attempt' => $this->attempt]);

        // The retry dispatch must never abort the termination loop: this
        // job runs before the prayer warmers in the after-response queue,
        // and a down retry transport would otherwise cancel them. The next
        // lookup re-triggers resolution anyway.
        try {
            self::dispatch($this->latitude, $this->longitude, $this->attempt + 1)
                ->delay(now()->addMinutes(self::RETRY_DELAY_MINUTES));
        } catch (Throwable $exception) {
            Log::warning('GPS zone retry dispatch failed; the next lookup will re-trigger.', [
                'attempt' => $this->attempt,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
