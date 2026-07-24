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

    public function syncSpeakerSlugsForName(string $name): bool
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            return false;
        }

        $speakers = Person::query()
            ->where('persons.name', $normalizedName)
            ->with(['addresses'])
            ->get();

        return $this->syncOrderedModels($speakers, fn (Person $speaker): bool => $this->syncSpeakerSlug($speaker));
    }

    public function syncSpeakerSlug(Person $speaker): bool
    {
        $slug = $this->forSpeaker($speaker);

        return $this->syncCanonicalSlugAction->persist($speaker, $slug);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $name, array $payload = [], ?string $ignoreSpeakerId = null): string
    {
        $normalizedName = trim($name);
        $displayName = $this->displayName($normalizedName);
        $nameSlug = Str::slug($displayName !== '' ? $displayName : $normalizedName);

        if ($nameSlug === '') {
            $nameSlug = 'speaker';
        }

        $locationSuffix = $this->locationSuffix($payload);

        return $this->buildUniqueSlug(
            Person::class,
            $nameSlug,
            [],
            $locationSuffix,
            $ignoreSpeakerId,
        );
    }

    public function forSpeaker(Person $speaker): string
    {
        $speaker->loadMissing(['addresses']);

        $address = $speaker->primaryAddress();

        return $this->handle(
            $speaker->name,
            [
                'city' => $address?->city,
                'state' => $address?->state,
                'country_id' => $address?->country_id,
                'country_code' => $address?->country_code,
            ],
            (string) $speaker->getKey(),
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
