<?php

namespace App\Jobs;

use App\Actions\References\GenerateReferenceSlugAction;
use App\Models\Reference;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillReferenceSlugs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(
        GenerateReferenceSlugAction $generateReferenceSlugAction,
        PublicListingsCache $publicListingsCache,
    ): void {
        Reference::query()
            ->orderBy('title')
            ->orderBy('id')
            ->chunk(100, function ($references) use ($generateReferenceSlugAction): void {
                foreach ($references as $reference) {
                    $slug = $generateReferenceSlugAction->forReference($reference);

                    if ($reference->slug === $slug) {
                        continue;
                    }

                    Reference::withoutTimestamps(function () use ($reference, $slug): void {
                        $reference->forceFill([
                            'slug' => $slug,
                        ])->saveQuietly();
                    });
                }
            });

        $publicListingsCache->bustMajlisListing();
    }

    public function uniqueId(): string
    {
        return 'reference-slug-backfill';
    }
}
