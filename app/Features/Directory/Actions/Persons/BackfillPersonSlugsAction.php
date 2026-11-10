<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Recompute person slugs without touching updated_at or firing save hooks.
 */
final readonly class BackfillPersonSlugsAction
{
    public function __construct(
        private GeneratePersonSlugAction $slugGenerator,
    ) {}

    /**
     * @return list<string> Person IDs whose slug changed.
     */
    public function handle(): array
    {
        $updatedPersonIds = [];

        DirectoryModel::query('person')
            ->with(['addresses.country'])
            ->orderBy('name')
            ->orderBy('id')
            ->chunk(100, function ($persons) use (&$updatedPersonIds): void {
                foreach ($persons as $person) {
                    if (! $person instanceof Model) {
                        continue;
                    }

                    $slug = $this->slugGenerator->forPerson($person);

                    if ($person->getAttribute('slug') === $slug) {
                        continue;
                    }

                    /** @var class-string<Model> $personClass */
                    $personClass = DirectoryModel::person();

                    $personClass::withoutTimestamps(function () use ($person, $slug): void {
                        $person->forceFill(['slug' => $slug])->saveQuietly();
                    });

                    $updatedPersonIds[] = (string) $person->getKey();
                }
            });

        return array_values(array_unique($updatedPersonIds));
    }
}
