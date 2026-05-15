<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Models\Country;

trait EnsuresMalaysiaCountry
{
    protected function ensureMalaysiaCountry(): Country
    {
        $country = Country::query()->find(132);

        if ($country instanceof Country) {
            return $country;
        }

        $country = new Country;
        $country->forceFill([
            'id' => 132,
            'name' => 'Malaysia',
            'iso2' => 'MY',
            'iso3' => 'MYS',
            'phone_code' => '60',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
            'status' => 1,
        ]);
        $country->save();

        return $country;
    }
}
