<?php

namespace App\Actions\Speakers;

use App\Actions\Slugs\Concerns\BuildsUniqueSlug;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\Concerns\ResolvesLocationSuffix;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Speaker;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateSpeakerSlugAction
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

        $speakers = Speaker::query()
            ->where('speakers.name', $normalizedName)
            ->with(['addresses'])
            ->get();

        return $this->syncOrderedModels($speakers, fn (Speaker $speaker): bool => $this->syncSpeakerSlug($speaker));
    }

    public function syncSpeakerSlug(Speaker $speaker): bool
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
        $displayName = $this->displayName($normalizedName, $payload);
        $nameSlug = Str::slug($displayName !== '' ? $displayName : $normalizedName);

        if ($nameSlug === '') {
            $nameSlug = 'speaker';
        }

        $locationSuffix = $this->locationSuffix($payload);

        return $this->buildUniqueSlug(
            Speaker::class,
            $nameSlug,
            [],
            $locationSuffix,
            $ignoreSpeakerId,
        );
    }

    public function forSpeaker(Speaker $speaker): string
    {
        $speaker->loadMissing(['addresses']);

        $address = $speaker->primaryAddress();

        return $this->handle(
            $speaker->name,
            [
                'honorific' => $speaker->honorific,
                'pre_nominal' => $speaker->pre_nominal,
                'post_nominal' => $speaker->post_nominal,
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function displayName(string $name, array $payload): string
    {
        return Speaker::formatDisplayedName(
            $name,
            $payload['honorific'] ?? null,
            $payload['pre_nominal'] ?? null,
            $payload['post_nominal'] ?? null,
        );
    }
}
