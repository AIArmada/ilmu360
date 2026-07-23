<?php

namespace App\Actions\Venues;

use App\Actions\Slugs\Concerns\BuildsUniqueSlug;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\Concerns\ResolvesLocationSuffix;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Venue;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateVenueSlugAction
{
    use AsAction;
    use BuildsUniqueSlug;
    use InteractsWithOrderedSlugModels;
    use ResolvesLocationSuffix;

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

        return $this->buildUniqueSlug(
            Venue::class,
            $nameSlug,
            [],
            $locationSuffix,
            $ignoreVenueId,
        );
    }

    public function forVenue(Venue $venue): string
    {
        $venue->loadMissing(['addresses']);

        $address = $venue->primaryAddress();

        return $this->handle(
            $venue->name,
            [
                'country_id' => $address?->country_id,
                'country_code' => $address?->country_code,
                'state' => $address?->state,
                'city' => $address?->city,
                'admin_area_1_id' => $address?->admin_area_1_id,
                'admin_area_2_id' => $address?->admin_area_2_id,
            ],
            (string) $venue->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function locationSuffix(array $address): string
    {
        $city = $this->firstFilled([
            $address['city'] ?? null,
            $address['admin_area_2_name'] ?? null,
            $this->areaName($address['admin_area_2_id'] ?? null),
        ]);
        $state = $this->firstFilled([
            $address['state'] ?? null,
            $address['admin_area_1_name'] ?? null,
            $this->areaName($address['admin_area_1_id'] ?? null),
        ]);
        $countryCode = $this->resolveCountryCode($address, true);

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
}
