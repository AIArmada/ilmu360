<?php

namespace App\Data\Api\Frontend\Search;

use App\Models\Address;
use App\Support\Location\PublicCountryRegistry;
use Spatie\LaravelData\Data;

class CountryData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $iso2,
        public ?string $key,
    ) {}

    public static function fromAddress(?Address $address): ?self
    {
        if (! $address instanceof Address || ! is_numeric($address->country_id)) {
            return null;
        }

        $address->loadMissing('country');

        if ($address->country === null) {
            return null;
        }

        $countryId = (int) $address->country->id;

        return new self(
            id: $countryId,
            name: (string) $address->country->name,
            iso2: strtoupper((string) $address->country->iso2),
            key: app(PublicCountryRegistry::class)->keyForCountryId($countryId),
        );
    }
}
