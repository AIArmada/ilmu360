<?php

namespace App\Actions\Venues;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Venue;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateVenueSlugAction
{
    use AsAction;
    use InteractsWithOrderedSlugModels;

    public function __construct(
        private readonly SyncCanonicalSlugAction $syncCanonicalSlugAction,
    ) {}

    public function syncVenueSlugsForName(string $name): bool
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            return false;
        }

        $venues = Venue::query()
            ->where('venues.name', $normalizedName)
            ->with(['addresses'])
            ->get();

        return $this->syncOrderedModels($venues, fn (Venue $venue): bool => $this->syncVenueSlug($venue));
    }

    public function syncVenueSlug(Venue $venue): bool
    {
        $slug = $this->forVenue($venue);

        return $this->syncCanonicalSlugAction->persist($venue, $slug);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    public function handle(string $name, array $address = [], ?string $ignoreVenueId = null): string
    {
        $normalizedName = trim($name);
        $nameSlug = Str::slug($normalizedName);

        if ($nameSlug === '') {
            $nameSlug = 'venue';
        }

        $locationSuffix = $this->locationSuffix($address);
        $sequence = $this->nextSequenceForExactName(
            $normalizedName,
            $locationSuffix,
            $ignoreVenueId,
        );

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
        } while ($this->slugExists($candidate, $ignoreVenueId));

        return $candidate;
    }

    public function forVenue(Venue $venue): string
    {
        $venue->loadMissing(['addresses']);

        $address = $venue->addressModel;

        return $this->handle(
            $venue->name,
            [
                'country_id' => $address?->country_id,
                'country_code' => $address?->country_code,
                'state' => $address?->state,
                'city' => $address?->city,
                'admin_area_1_id' => $address?->admin_area_1_id,
                'admin_area_2_id' => $address?->admin_area_2_id,
                'admin_area_3_id' => $address?->admin_area_3_id,
            ],
            (string) $venue->getKey(),
        );
    }

    private function nextSequenceForExactName(string $name, string $locationSuffix, ?string $ignoreVenueId): int
    {
        $matchingVenues = Venue::query()
            ->where('venues.name', $name)
            ->with(['addresses'])
            ->get()
            ->filter(fn (Venue $venue): bool => $this->locationSuffixForVenue($venue) === $locationSuffix);

        if ($ignoreVenueId !== null && $ignoreVenueId !== '') {
            $existingSequence = $this->existingModelSequence($matchingVenues, $ignoreVenueId);

            if ($existingSequence !== null) {
                return $existingSequence;
            }

            $matchingVenues = $matchingVenues
                ->reject(fn (Venue $venue): bool => (string) $venue->getKey() === $ignoreVenueId)
                ->values();
        }

        $matchingCount = $matchingVenues->count();

        return $matchingCount > 0 ? $matchingCount + 1 : 1;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function locationSuffix(array $address): string
    {
        $city = $this->firstFilled([
            $address['city'] ?? null,
            $address['admin_area_3_name'] ?? null,
            $this->areaName($address['admin_area_3_id'] ?? null),
            $address['admin_area_2_name'] ?? null,
            $this->areaName($address['admin_area_2_id'] ?? null),
        ]);
        $state = $this->firstFilled([
            $address['state'] ?? null,
            $address['admin_area_1_name'] ?? null,
            $this->areaName($address['admin_area_1_id'] ?? null),
        ]);
        $countryCode = $this->resolveCountryCode($address);

        $segments = [];

        foreach ([
            $this->slugSegment($city),
            $this->slugSegment($state),
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

    private function locationSuffixForVenue(Venue $venue): string
    {
        $venue->loadMissing(['addresses']);

        $address = $venue->addressModel;

        return $this->locationSuffix([
            'country_id' => $address?->country_id,
            'country_code' => $address?->country_code,
            'state' => $address?->state,
            'city' => $address?->city,
            'admin_area_1_id' => $address?->admin_area_1_id,
            'admin_area_2_id' => $address?->admin_area_2_id,
            'admin_area_3_id' => $address?->admin_area_3_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function resolveCountryCode(array $address): ?string
    {
        $countryCode = $address['country_code'] ?? null;

        if (is_string($countryCode) && trim($countryCode) !== '') {
            return trim($countryCode);
        }

        $countryId = $this->uuidValue($address['country_id'] ?? null);

        if ($countryId === null) {
            return null;
        }

        $resolved = AddressCountry::query()->whereKey($countryId)->value('iso2');

        return is_string($resolved) && trim($resolved) !== '' ? $resolved : null;
    }

    private function slugExists(string $slug, ?string $ignoreVenueId): bool
    {
        return Venue::query()
            ->where('slug', $slug)
            ->when(
                $ignoreVenueId !== null && $ignoreVenueId !== '',
                fn ($query) => $query->where('venues.id', '!=', $ignoreVenueId),
            )
            ->exists();
    }

    private function slugSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::slug($value);

        return $segment !== '' ? $segment : null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function areaName(mixed $value): ?string
    {
        $areaId = $this->uuidValue($value);

        if ($areaId === null) {
            return null;
        }

        $name = AddressArea::query()->whereKey($areaId)->value('name');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    private function countryCodeSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::lower(trim($value));

        return $segment !== '' ? $segment : null;
    }

    private function uuidValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return Str::isUuid($trimmed) ? $trimmed : null;
    }
}
