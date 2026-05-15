<?php

namespace App\Actions\Speakers;

use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Speaker;
use App\Support\Location\PreferredCountryResolver;
use App\Support\Location\PublicCountryRegistry;
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
            ->with([
                'address.country',
            ])
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

        $countrySuffix = $this->countrySuffix($payload);
        $sequence = $this->nextSequenceForExactIdentity($normalizedName, $displayName, $countrySuffix, $ignoreSpeakerId);

        do {
            $candidateParts = [$nameSlug];

            if ($sequence > 1) {
                $candidateParts[] = (string) $sequence;
            }

            if ($countrySuffix !== '') {
                $candidateParts[] = $countrySuffix;
            }

            $candidate = implode('-', $candidateParts);
            $sequence++;
        } while ($this->slugExists($candidate, $ignoreSpeakerId));

        return $candidate;
    }

    public function forSpeaker(Speaker $speaker): string
    {
        $speaker->loadMissing([
            'address.country',
        ]);

        $address = $speaker->addressModel;

        return $this->handle(
            $speaker->name,
            [
                'honorific' => $speaker->honorific,
                'pre_nominal' => $speaker->pre_nominal,
                'post_nominal' => $speaker->post_nominal,
                'country_id' => $address?->country_id,
                'country_code' => $address?->country?->iso2,
            ],
            (string) $speaker->getKey(),
        );
    }

    private function nextSequenceForExactIdentity(string $name, string $displayName, string $countrySuffix, ?string $ignoreSpeakerId): int
    {
        $matchingSpeakers = Speaker::query()
            ->where('speakers.name', $name)
            ->with([
                'address.country',
            ])
            ->get()
            ->filter(fn (Speaker $speaker): bool => $this->countrySuffixForSpeaker($speaker) === $countrySuffix
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
    private function countrySuffix(array $payload): string
    {
        $registry = app(PublicCountryRegistry::class);
        $countryCode = $this->resolveCountryCode($payload);
        $countryProvided = $this->countrySelectionProvided($payload);
        $countryId = $registry->resolveCountryId(
            $payload['country_id'] ?? null,
            $countryCode,
            $payload['country_key'] ?? null,
        );

        if (($countryCode === null || $countryId === null) && is_array($payload['address'] ?? null)) {
            /** @var array<string, mixed> $address */
            $address = $payload['address'];
            $countryProvided = $countryProvided || $this->countrySelectionProvided($address);
            $countryCode ??= $this->resolveCountryCode($address);
            $countryId ??= $registry->resolveCountryId(
                $address['country_id'] ?? null,
                $countryCode,
                $address['country_key'] ?? null,
            );
        }

        if (! $countryProvided && $countryCode === null && $countryId === null) {
            $countryId = $registry->normalizeCountryId(app(PreferredCountryResolver::class)->resolveId());
        }

        if ($countryCode === null && $countryId !== null) {
            $countryCode = $registry->countryDataForId($countryId)['iso2'] ?? null;
        }

        return $this->countryCodeSegment($countryCode) ?? '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function countrySelectionProvided(array $payload): bool
    {
        foreach (['country_id', 'country_code', 'country_key'] as $field) {
            $value = $payload[$field] ?? null;

            if (is_int($value)) {
                return true;
            }

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function countrySuffixForSpeaker(Speaker $speaker): string
    {
        $speaker->loadMissing([
            'address.country',
        ]);

        $address = $speaker->addressModel;

        return $this->countrySuffix([
            'country_id' => $address?->country_id,
            'country_code' => $address?->country?->iso2,
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

    /**
     * @param  array<string, mixed>  $address
     */
    private function resolveCountryCode(array $address): ?string
    {
        $countryCode = $address['country_code'] ?? null;

        if (! is_string($countryCode)) {
            return null;
        }

        $countryCode = trim($countryCode);

        return $countryCode !== '' ? $countryCode : null;
    }

    private function slugExists(string $slug, ?string $ignoreSpeakerId): bool
    {
        return Speaker::query()
            ->where('slug', $slug)
            ->when(
                $ignoreSpeakerId !== null && $ignoreSpeakerId !== '',
                fn ($query) => $query->where('speakers.id', '!=', $ignoreSpeakerId),
            )
            ->exists();
    }

    private function countryCodeSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::lower(trim($value));

        return $segment !== '' ? $segment : null;
    }
}
