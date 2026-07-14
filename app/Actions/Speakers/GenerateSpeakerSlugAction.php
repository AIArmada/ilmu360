<?php

namespace App\Actions\Speakers;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\CommerceSupport\Support\SlugGenerator;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Speaker;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateSpeakerSlugAction
{
    use AsAction;
    use InteractsWithOrderedSlugModels;

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
        $sequence = $this->nextSequenceForExactIdentity($normalizedName, $displayName, $locationSuffix, $ignoreSpeakerId);

        do {
            $candidateParts = [$nameSlug];

            if ($sequence > 1) {
                $candidateParts[] = (string) $sequence;
            }

            if ($locationSuffix !== '') {
                $candidateParts[] = $locationSuffix;
            }

            $candidate = implode('-', $candidateParts);
            $sequence++;
        } while (SlugGenerator::exists(Speaker::class, $candidate, $ignoreSpeakerId));

        return $candidate;
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

    private function nextSequenceForExactIdentity(string $name, string $displayName, string $locationSuffix, ?string $ignoreSpeakerId): int
    {
        $matchingSpeakers = Speaker::query()
            ->where('speakers.name', $name)
            ->with(['addresses'])
            ->get()
            ->filter(fn (Speaker $speaker): bool => $this->locationSuffixForSpeaker($speaker) === $locationSuffix
                && $this->displayNameForSpeaker($speaker) === $displayName);

        if ($ignoreSpeakerId !== null && $ignoreSpeakerId !== '') {
            $existingSequence = $this->existingModelSequence($matchingSpeakers, $ignoreSpeakerId);

            if ($existingSequence !== null) {
                return $existingSequence;
            }

            $matchingSpeakers = $matchingSpeakers
                ->reject(fn (Speaker $speaker): bool => (string) $speaker->getKey() === $ignoreSpeakerId)
                ->values();
        }

        $matchingCount = $matchingSpeakers->count();

        return $matchingCount > 0 ? $matchingCount + 1 : 1;
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

    private function locationSuffixForSpeaker(Speaker $speaker): string
    {
        $speaker->loadMissing(['addresses']);

        $address = $speaker->primaryAddress();

        return $this->locationSuffix([
            'city' => $address?->city,
            'state' => $address?->state,
            'country_id' => $address?->country_id,
            'country_code' => $address?->country_code,
        ]);
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

    private function displayNameForSpeaker(Speaker $speaker): string
    {
        return Speaker::formatDisplayedName(
            $speaker->name,
            $speaker->honorific,
            $speaker->pre_nominal,
            $speaker->post_nominal,
        );
    }

    private function slugSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::slug($value);

        return $segment !== '' ? $segment : null;
    }

    private function countryCodeSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::lower(trim($value));

        return $segment !== '' ? $segment : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCountryCode(array $payload): ?string
    {
        $countryId = $this->uuidValue($payload['country_id'] ?? null);

        if ($countryId !== null) {
            $resolved = AddressCountry::query()->whereKey($countryId)->value('iso2');

            if (is_string($resolved) && trim($resolved) !== '') {
                return trim($resolved);
            }
        }

        $countryCode = $payload['country_code'] ?? null;

        if (is_string($countryCode) && trim($countryCode) !== '') {
            return trim($countryCode);
        }

        return null;
    }

    private function uuidValue(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
