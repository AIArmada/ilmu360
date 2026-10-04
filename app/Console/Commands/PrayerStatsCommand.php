<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventTimeExpression;
use App\Services\Prayer\PrayerCircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

class PrayerStatsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:prayer:stats
                            {--json : Output machine-readable JSON}
                            {--alert-queue-depth= : Exit non-zero and log an error when total queue depth exceeds N}';

    /**
     * @var string
     */
    protected $description = 'Report prayer-times provenance distribution, provider health, and queue depth';

    public function handle(PrayerCircuitBreaker $breaker): int
    {
        $depths = $this->queueDepths();
        $total = $depths === [] ? -1 : array_sum($depths);

        $stats = [
            'sources' => $this->sourceDistribution(),
            'missing_mappings' => $this->missingMappings(),
            'breakers' => $this->breakerStates($breaker),
            'queue_depth' => $total,
            'queue_depths' => $depths,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));

            return $this->checkQueueAlert($total);
        }

        $this->info('Prayer-times provenance (event_time_expressions, prayer anchors)');
        $this->table(['Source', 'Expressions'], $this->rows($stats['sources']));

        $this->info('Countries without provider mappings (7-day window)');
        $this->table(['Country', 'Resolutions'], $this->rows($stats['missing_mappings']));

        $this->info('Circuit breakers');
        $breakerRows = [];

        foreach ($stats['breakers'] as $provider => $state) {
            $breakerRows[] = [$provider, $state['failures'], $state['open'] ? 'OPEN' : 'closed'];
        }

        $this->table(['Provider', 'Failures', 'State'], $breakerRows);

        $breakdown = [];

        foreach ($depths as $queue => $depth) {
            $breakdown[] = "{$queue}: {$depth}";
        }

        $this->info($depths === []
            ? 'Queue depth (unavailable)'
            : 'Queue depth ('.implode(', ', $breakdown)."; total: {$total})");

        return $this->checkQueueAlert($total);
    }

    /**
     * @return array<string, int>
     */
    private function sourceDistribution(): array
    {
        $counts = [];

        // Console has no ambient owner; the provenance report is global by design.
        OwnerContext::withOwner(null, function () use (&$counts): void {
            EventTimeExpression::query()
                ->where('anchor_type', 'prayer')
                ->select(['id', 'metadata'])
                ->chunk(1000, function ($expressions) use (&$counts): void {
                    foreach ($expressions as $expression) {
                        $metadata = $expression->metadata;
                        $source = is_array($metadata) ? ($metadata['prayer']['source'] ?? null) : null;
                        $source = is_string($source) && $source !== '' ? $source : 'hardcoded:untracked';
                        $counts[$source] = ($counts[$source] ?? 0) + 1;
                    }
                });
        });

        arsort($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function missingMappings(): array
    {
        try {
            /** @var string|null $store */
            $store = config('prayer.cache.store');
            $repository = Cache::store($store);
            $prefix = (string) config('prayer.cache.prefix', 'prayer:v3');

            $countries = (array) $repository->get("{$prefix}:metric:missing-mapping:countries", []);
            $counts = [];
            $today = CarbonImmutable::now('UTC');

            foreach ($countries as $country) {
                if (! is_string($country) || $country === '') {
                    continue;
                }

                // Dated counters sum the preceding seven days only; expired
                // days read as zero.
                $total = 0;

                for ($ago = 0; $ago < 7; $ago++) {
                    $day = $today->subDays($ago)->format('Y-m-d');
                    $total += (int) $repository->get("{$prefix}:metric:missing-mapping:{$country}:{$day}", 0);
                }

                $counts[$country] = $total;
            }

            arsort($counts);

            return $counts;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, array{failures: int, open: bool}>
     */
    private function breakerStates(PrayerCircuitBreaker $breaker): array
    {
        $states = [];
        $map = config('prayer.provider_map', []);

        foreach (array_keys(is_array($map) ? $map : []) as $key) {
            if ($key === 'null' || ! is_string($key)) {
                continue;
            }

            $states[$key] = $breaker->state($key);
        }

        return $states;
    }

    /**
     * Depth per monitored queue; empty when the measurement itself fails
     * (callers treat that as depth -1 and skip the alert, as before).
     *
     * @return array<string, int>
     */
    private function queueDepths(): array
    {
        $depths = [];

        foreach ($this->monitoredQueues() as $queue) {
            try {
                // Null measures the driver's default queue; named queues
                // measure explicitly. Sync reports 0 for every queue.
                $depths[$queue] = max(0, Queue::size($queue === 'default' ? null : $queue));
            } catch (Throwable) {
                return [];
            }
        }

        return $depths;
    }

    /**
     * @return list<string>
     */
    private function monitoredQueues(): array
    {
        $configured = config('prayer.monitored_queues');

        if (is_array($configured)) {
            $queues = [];

            foreach ($configured as $queue) {
                if (is_string($queue) && trim($queue) !== '') {
                    $queues[] = trim($queue);
                }
            }

            $queues = array_values(array_unique($queues));

            if ($queues !== []) {
                return $queues;
            }
        }

        // Default: every Horizon-supervised queue, so media and
        // notification backlogs count toward the total instead of being
        // silently excluded while the label claims "all jobs".
        $queues = [];
        $supervisors = config('horizon.defaults', []);

        if (is_array($supervisors)) {
            foreach ($supervisors as $supervisor) {
                if (! is_array($supervisor)) {
                    continue;
                }

                foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                    if (is_string($queue) && trim($queue) !== '') {
                        $queues[] = trim($queue);
                    }
                }
            }
        }

        $queues = array_values(array_unique($queues));

        return $queues === [] ? ['default'] : $queues;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<list<string|int>>
     */
    private function rows(array $counts): array
    {
        $rows = [];

        foreach ($counts as $label => $count) {
            $rows[] = [$label, $count];
        }

        return $rows === [] ? [['(none)', 0]] : $rows;
    }

    private function checkQueueAlert(int $depth): int
    {
        $threshold = $this->option('alert-queue-depth');

        if ($threshold === null || $depth < 0) {
            return self::SUCCESS;
        }

        if ($depth <= (int) $threshold) {
            return self::SUCCESS;
        }

        $message = "Prayer queue-depth alert: {$depth} pending jobs exceed threshold {$threshold}.";

        if (is_string(config('logging.channels.slack.url')) && config('logging.channels.slack.url') !== '') {
            Log::channel('slack')->error($message);
        }

        Log::error($message);

        if (! $this->option('json')) {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
