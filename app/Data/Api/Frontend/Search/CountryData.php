<?php

namespace App\Data\Api\Frontend\Search;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class CountryData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $iso2,
        public ?string $key,
    ) {}

    public static function fromAddress(?Address $address): ?self
    {
        if (! $address instanceof Address || ! is_string($address->country_id)) {
            return null;
        }

        $address->loadMissing('country');
        $country = $address->getRelation('country');

        if (! $country instanceof AddressCountry) {
            return null;
        }

        return new self(
            id: (string) $country->id,
            name: (string) $country->name,
            iso2: strtoupper((string) $country->iso2),
            key: Str::slug((string) $country->name),
        );
    }
}
