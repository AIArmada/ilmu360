<?php

namespace App\Jobs;

use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Models\Person;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillPersonSlugs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(
        GenerateEventSlugAction $generateEventSlugAction,
        GeneratePersonSlugAction $generatePersonSlugAction,
        PublicListingsCache $publicListingsCache,
    ): void {
        $updatedSpeakerIds = [];

        Person::query()
            ->with([
                'addresses.country',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->chunk(100, function ($speakers) use ($generatePersonSlugAction, &$updatedSpeakerIds): void {
                foreach ($speakers as $speaker) {
                    $slug = $generatePersonSlugAction->forSpeaker($speaker);

                    if ($speaker->slug === $slug) {
                        continue;
                    }

                    Person::withoutTimestamps(function () use ($speaker, $slug): void {
                        $speaker->forceFill([
                            'slug' => $slug,
                        ])->saveQuietly();
                    });

                    $updatedSpeakerIds[] = (string) $speaker->getKey();
                }
            });

        foreach (array_values(array_unique($updatedSpeakerIds)) as $speakerId) {
            $generateEventSlugAction->syncEventSlugsForSpeakerId($speakerId);
        }

        $publicListingsCache->bustMajlisListing();
    }

    public function uniqueId(): string
    {
        return 'speaker-slug-backfill';
    }
}
