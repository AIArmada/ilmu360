<?php

namespace App\Actions\Venues;

use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Subdistrict;
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
            ->with([
                'address.country',
                'address.state',
                'address.district',
                'address.subdistrict',
            ])
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
        $venue->loadMissing([
            'address.country',
            'address.state',
            'address.district',
            'address.subdistrict',
        ]);

        $address = $venue->addressModel;

        return $this->handle(
            $venue->name,
            [
                'country_id' => $address?->country_id,
                'country_code' => $address?->country?->iso2,
                'state_id' => $address?->state_id,
                'state_name' => $address?->state?->name,
                'district_id' => $address?->district_id,
                'district_name' => $address?->district?->name,
                'subdistrict_id' => $address?->subdistrict_id,
                'subdistrict_name' => $address?->subdistrict?->name,
            ],
            (string) $venue->getKey(),
        );
    }

    private function nextSequenceForExactName(string $name, string $locationSuffix, ?string $ignoreVenueId): int
    {
        $matchingVenues = Venue::query()
            ->where('venues.name', $name)
            ->with([
                'address.country',
                'address.state',
                'address.district',
                'address.subdistrict',
            ])
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
        $subdistrict = $this->resolveSubdistrict($address);
        $district = $this->resolveDistrict($address);
        $state = $this->resolveState($address);
        $countryCode = $this->resolveCountryCode($address);

        if ($subdistrict instanceof Subdistrict) {
            if (! $district instanceof District && $subdistrict->district_id !== null) {
                $district = District::query()->find($subdistrict->district_id);
            }

            if (! $state instanceof State && $subdistrict->state_id !== null) {
                $state = State::query()->find($subdistrict->state_id);
            }
        }

        if ($district instanceof District && $state === null && $district->state_id !== null) {
            $state = State::query()->find($district->state_id);
        }

        if ($countryCode === null) {
            $countryId = $this->integerValue($address['country_id'] ?? null)
                ?? ($subdistrict instanceof Subdistrict ? $subdistrict->country_id : null)
                ?? ($district instanceof District ? $district->country_id : null)
                ?? ($state instanceof State ? $state->country_id : null);

            if ($countryId !== null) {
                $countryCode = Country::query()
                    ->whereKey($countryId)
                    ->value('iso2');
            }
        }

        $segments = [];

        foreach ([
            $this->slugSegment($subdistrict instanceof Subdistrict ? $subdistrict->name : ($address['subdistrict_name'] ?? null)),
            $this->slugSegment($district instanceof District ? $district->name : ($address['district_name'] ?? null)),
            $this->slugSegment($state instanceof State ? $state->name : ($address['state_name'] ?? null)),
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
        $venue->loadMissing([
            'address.country',
            'address.state',
            'address.district',
            'address.subdistrict',
        ]);

        $address = $venue->addressModel;

        return $this->locationSuffix([
            'country_id' => $address?->country_id,
            'country_code' => $address?->country?->iso2,
            'state_id' => $address?->state_id,
            'state_name' => $address?->state?->name,
            'district_id' => $address?->district_id,
            'district_name' => $address?->district?->name,
            'subdistrict_id' => $address?->subdistrict_id,
            'subdistrict_name' => $address?->subdistrict?->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function resolveState(array $address): ?State
    {
        $stateId = $this->integerValue($address['state_id'] ?? null);

        if ($stateId === null) {
            return null;
        }

        return State::query()->find($stateId);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function resolveDistrict(array $address): ?District
    {
        $districtId = $this->integerValue($address['district_id'] ?? null);

        if ($districtId === null) {
            return null;
        }

        return District::query()->find($districtId);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function resolveSubdistrict(array $address): ?Subdistrict
    {
        $subdistrictId = $this->integerValue($address['subdistrict_id'] ?? null);

        if ($subdistrictId === null) {
            return null;
        }

        return Subdistrict::query()->find($subdistrictId);
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

        return null;
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

    private function countryCodeSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $segment = Str::lower(trim($value));

        return $segment !== '' ? $segment : null;
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || ! ctype_digit($trimmed)) {
            return null;
        }

        $integer = (int) $trimmed;

        return $integer > 0 ? $integer : null;
    }
}
