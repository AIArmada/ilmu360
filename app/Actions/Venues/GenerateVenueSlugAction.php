<?php

namespace App\Actions\Venues;

use AIArmada\Addressing\Support\LocationSlugSegments;
use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\StableModelOrder;
use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Venue;
use App\Support\Location\AddressAssignments;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateVenueSlugAction
{
    use AsAction;

    public function __construct(
        private readonly SyncSlugRedirectAction $syncSlugRedirectAction,
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

        return StableModelOrder::sync($venues, fn (Venue $venue): bool => $this->syncVenueSlug($venue));
    }

    public function syncVenueSlug(Venue $venue): bool
    {
        $slug = $this->forVenue($venue);

        return CanonicalSlug::persist($venue, $slug, $this->syncSlugRedirectAction);
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

        return UniqueSlug::build(
            Venue::class,
            $nameSlug,
            [],
            $locationSuffix,
            $ignoreVenueId,
        );
    }

    public function forVenue(Venue $venue): string
    {
        $venue->unsetRelation('addresses');
        $venue->load(['addresses']);

        $address = $venue->primaryAddress();

        return $this->handle(
            $venue->name,
            [
                'country_id' => $address?->country_id,
                'country_code' => $address?->country_code,
                'state' => $address?->state,
                'city' => $address?->city,
                'area_assignments' => AddressAssignments::forAddress($address),
            ],
            (string) $venue->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function locationSuffix(array $address): string
    {
        $assignments = (array) ($address['area_assignments'] ?? []);

        return LocationSlugSegments::suffix(
            $address,
            $assignments[AddressAssignments::ADMINISTRATIVE_SUBDIVISION] ?? null,
            $assignments[AddressAssignments::ADMINISTRATIVE_DISTRICT] ?? null,
        );
    }
}
