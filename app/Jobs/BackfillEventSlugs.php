<?php

namespace App\Jobs;

use App\Actions\Events\GenerateEventSlugAction;
use App\Models\Event;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillEventSlugs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(
        GenerateEventSlugAction $generateEventSlugAction,
        PublicListingsCache $publicListingsCache,
    ): void {
        Event::query()
            ->orderBy('title')
            ->orderBy('id')
            ->chunk(100, function ($events) use ($generateEventSlugAction): void {
                foreach ($events as $event) {
                    $slug = $generateEventSlugAction->forEvent($event);

                    if ($event->slug === $slug) {
                        continue;
                    }

                    Event::withoutTimestamps(function () use ($event, $slug): void {
                        $event->forceFill([
                            'slug' => $slug,
                        ])->saveQuietly();
                    });
                }
            });

        $publicListingsCache->bustMajlisListing();
    }

    public function uniqueId(): string
    {
        return 'event-slug-backfill';
    }
}
