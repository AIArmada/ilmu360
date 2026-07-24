<?php

namespace App\Actions\Persons;

use App\Actions\Slugs\Concerns\BuildsUniqueSlug;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\Concerns\ResolvesLocationSuffix;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Person;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GeneratePersonSlugAction
{
    use AsAction;
    use BuildsUniqueSlug;
    use InteractsWithOrderedSlugModels;
    use ResolvesLocationSuffix;

    public function __construct(
        private readonly SyncCanonicalSlugAction $syncCanonicalSlugAction,
    ) {}

    public function syncPersonSlugsForName(string $name): bool
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            return false;
        }

        $persons = Person::query()
            ->where('persons.name', $normalizedName)
            ->with(['addresses'])
            ->get();

        return $this->syncOrderedModels($persons, fn (Person $person): bool => $this->syncPersonSlug($person));
    }

    public function syncPersonSlug(Person $person): bool
    {
        $slug = $this->forPerson($person);

        return $this->syncCanonicalSlugAction->persist($person, $slug);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $name, array $payload = [], ?string $ignorePersonId = null): string
    {
        $normalizedName = trim($name);
        $displayName = $this->displayName($normalizedName);
        $nameSlug = Str::slug($displayName !== '' ? $displayName : $normalizedName);

        if ($nameSlug === '') {
            $nameSlug = 'person';
        }

        $locationSuffix = $this->locationSuffix($payload);

        return $this->buildUniqueSlug(
            Person::class,
            $nameSlug,
            [],
            $locationSuffix,
            $ignorePersonId,
        );
    }

    public function forPerson(Person $person): string
    {
        $person->loadMissing(['addresses']);

        $address = $person->primaryAddress();

        return $this->handle(
            $person->name,
            [
                'city' => $address?->city,
                'state' => $address?->state,
                'country_id' => $address?->country_id,
                'country_code' => $address?->country_code,
            ],
            (string) $person->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function locationSuffix(array $payload): string
    {
        $countryCode = $this->resolveCountryCode($payload);
        $segments = [];

        foreach ([
            $this->slugSegment($payload['city'] ?? null),
            $this->slugSegment($payload['state'] ?? null),
            $this->countryCodeSegment($countryCode),
        ] as $segment) {
            if ($segment === null) {
                continue;
            }

            if (($segments[array_key_last($segments)] ?? null) === $segment) {
                continue;
            }

            $segments[] = $segment;
        }

        return implode('-', $segments);
    }

    private function displayName(string $name): string
    {
        return Person::formatDisplayedName($name);
    }
}
