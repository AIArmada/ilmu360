<?php

namespace App\Console\Commands;

use App\Jobs\BackfillVenueSlugs;
use App\Models\Venue;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

class QueueBackfillVenueSlugs extends Command
{
    public const CHUNK_SIZE = 250;

    public const LOCK_KEY = 'venue-slug-backfill';

    private const int LOCK_TTL_SECONDS = 3600;

    protected $signature = 'venues:queue-slug-backfill';

    protected $description = 'Queue regeneration of venue slugs using the geographic slug format.';

    public function handle(): int
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            $this->info('Venue slug backfill is already queued or running.');

            return self::SUCCESS;
        }

        $jobs = [];
        $queuedJobs = 0;
        $totalVenues = 0;

        Venue::query()
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($venues) use (&$jobs, &$queuedJobs, &$totalVenues): void {
                $venueIds = $venues
                    ->pluck('id')
                    ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
                    ->values()
                    ->all();

                if ($venueIds === []) {
                    return;
                }

                $jobs[] = new BackfillVenueSlugs($venueIds);

                $queuedJobs++;
                $totalVenues += count($venueIds);
            }, column: 'id');

        if ($queuedJobs === 0) {
            $lock->forceRelease();
            $this->info('No venues found to queue for slug backfill.');

            return self::SUCCESS;
        }

        try {
            Bus::batch($jobs)
                ->name('venue-slug-backfill')
                ->allowFailures()
                ->finally(function (Batch $batch): void {
                    app(PublicListingsCache::class)->bustMajlisListing();
                    Cache::lock(self::LOCK_KEY)->forceRelease();
                })
                ->dispatch();
        } catch (Throwable $throwable) {
            $lock->forceRelease();

            throw $throwable;
        }

        $this->info("Queued venue slug backfill batch with {$queuedJobs} jobs for {$totalVenues} venues.");

        return self::SUCCESS;
    }
}
