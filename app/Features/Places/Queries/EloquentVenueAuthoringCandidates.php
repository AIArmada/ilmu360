<?php

declare(strict_types=1);

namespace App\Features\Places\Queries;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressLocationScope;
use App\Features\Places\Api\Orm\VenueAuthoringCandidates;
use App\Features\Places\Models\Venue;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentVenueAuthoringCandidates implements VenueAuthoringCandidates
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_STATUSES = ['verified', 'pending'];

    public function options(?string $countryId = null): array
    {
        return $this->candidateQuery($countryId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function canUse(string $venueId): bool
    {
        return Venue::query()
            ->whereIn('venues.status', self::ALLOWED_STATUSES)
            ->whereKey($venueId)
            ->exists();
    }

    public function belongsToCountry(string $venueId, string $countryId): bool
    {
        $query = Venue::query()->whereKey($venueId);

        return app(AddressLocationScope::class)
            ->apply($query, new AddressLocationData(countryId: $countryId))
            ->exists();
    }

    /**
     * @return Builder<Venue>
     */
    private function candidateQuery(?string $countryId): Builder
    {
        $query = Venue::query()->whereIn('venues.status', self::ALLOWED_STATUSES);

        if (filled($countryId)) {
            app(AddressLocationScope::class)->apply($query, new AddressLocationData(countryId: $countryId));
        }

        return $query;
    }
}
