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
        $updatedPersonIds = [];

        Person::query()
            ->with([
                'addresses.country',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->chunk(100, function ($persons) use ($generatePersonSlugAction, &$updatedPersonIds): void {
                foreach ($persons as $person) {
                    $slug = $generatePersonSlugAction->forPerson($person);

                    if ($person->slug === $slug) {
                        continue;
                    }

                    Person::withoutTimestamps(function () use ($person, $slug): void {
                        $person->forceFill([
                            'slug' => $slug,
                        ])->saveQuietly();
                    });

                    $updatedPersonIds[] = (string) $person->getKey();
                }
            });

        foreach (array_values(array_unique($updatedPersonIds)) as $personId) {
            $generateEventSlugAction->syncEventSlugsForPersonId($personId);
        }

        $publicListingsCache->bustMajlisListing();
    }

    public function uniqueId(): string
    {
        return 'person-slug-backfill';
    }
}
