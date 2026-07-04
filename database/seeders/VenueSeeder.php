<?php

namespace Database\Seeders;

use AIArmada\Addressing\Models\AddressArea;
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
        $states = AddressArea::query()
            ->where('country_code', 'MY')
            ->where('level', 1)
            ->orderBy('name')
            ->get();

        for ($i = 0; $i < 50; $i++) {
            $venue = Venue::factory()->create();
            $state = $states->isNotEmpty() ? $states->random() : null;
            $district = $state instanceof AddressArea
                ? AddressArea::query()->where('parent_id', $state->id)->inRandomOrder()->first()
                : null;

            $this->seedPrimaryPackageAddress($venue, [
                'line1' => fake()->streetAddress(),
                'line2' => fake()->optional()->words(2, true),
                'postcode' => fake()->postcode(),
                'country_id' => $malaysia?->id,
                'admin_area_1_id' => $state instanceof AddressArea ? $state->id : null,
                'admin_area_2_id' => $district instanceof AddressArea ? $district->id : null,
                'latitude' => fake()->randomFloat(7, 1.0, 7.0),
                'longitude' => fake()->randomFloat(7, 99.0, 119.0),
                'provider_place_id' => fake()->optional()->numerify('ChI###########'),
                'waze_url' => fake()->optional()->url(),
            ]);
        }
    }
}
