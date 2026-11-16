<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Venues;

use AIArmada\Addressing\Support\LocationSlugSegments;
use AIArmada\CommerceSupport\Contracts\SlugRedirectRecorder;
use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\StableModelOrder;
use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Models\Venue;
use App\Platform\Location\AddressAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * owner-scoped venue slug generation and redirect synchronization.
 */
class GenerateVenueSlugAction
{
    use AsAction;

    public function __construct(
        private readonly SlugRedirectRecorder $slugRedirectRecorder,
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

        return StableModelOrder::sync($venues, fn (Model $venue): bool => $this->syncVenueSlug($venue));
    }

    public function syncVenueSlug(Model $venue): bool
    {
        $slug = $this->forVenue($venue);

        return CanonicalSlug::persist($venue, $slug, $this->slugRedirectRecorder);
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

    public function forVenue(Model $venue): string
    {
        $venue->unsetRelation('addresses');
        $venue->load(['addresses']);

        $address = method_exists($venue, 'primaryAddress') ? $venue->primaryAddress() : null;

        return $this->handle(
            (string) $venue->getAttribute('name'),
            [
                'country_id' => $address?->getAttribute('country_id'),
                'country_code' => $address?->getAttribute('country_code'),
                'state' => $address?->getAttribute('state'),
                'city' => $address?->getAttribute('city'),
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
