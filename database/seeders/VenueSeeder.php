<?php

namespace Database\Seeders;

use AIArmada\Addressing\Models\State;
use App\Models\Venue;
use Database\Seeders\Concerns\SeedsPackageAddresses;
use Illuminate\Database\Seeder;

class VenueSeeder extends Seeder
{
    use SeedsPackageAddresses;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Venue::query()->exists()) {
            return;
        }

        $malaysia = $this->malaysiaCountry();
        $states = $this->malaysiaPackageStates();

        for ($i = 0; $i < 50; $i++) {
            $venue = Venue::factory()->create();
            $state = $states->isNotEmpty() ? $states->random() : null;
            $district = $state instanceof State ? $this->randomDistrictForState($state) : null;
            $subdistrict = $this->randomSubdistrictForDistrict($district);

            $this->seedPrimaryPackageAddress($venue, $this->packageAddressAttributes([
                'line1' => fake()->streetAddress(),
                'line2' => fake()->optional()->words(2, true),
                'postcode' => fake()->postcode(),
                'country_id' => $malaysia?->id,
                'latitude' => fake()->randomFloat(7, 1.0, 7.0),
                'longitude' => fake()->randomFloat(7, 99.0, 119.0),
                'provider_place_id' => fake()->optional()->numerify('ChI###########'),
                'waze_url' => fake()->optional()->url(),
            ], $state, $district, $subdistrict));
        }
    }
}
