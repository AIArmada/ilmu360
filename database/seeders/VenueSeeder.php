<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\State;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VenueSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Venue::query()->exists()) {
            return;
        }

        Venue::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                $venuesToCreate = [];

                $count = 50;

                for ($i = 0; $i < $count; $i++) {
                    $venue = Venue::factory()->make();
                    $venueData = $venue->toArray();

                    // JSON encode array fields for raw insert
                    if (isset($venueData['facilities']) && is_array($venueData['facilities'])) {
                        $venueData['facilities'] = json_encode($venueData['facilities']);
                    }
                    $venuesToCreate[] = array_merge($venueData, [
                        'id' => (string) Str::uuid(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Bulk insert venues
                foreach (array_chunk($venuesToCreate, 100) as $chunk) {
                    Venue::insert($chunk);
                }

                // Create addresses for venues
                $venues = Venue::query()->doesntHave('address')->get();
                $addressesToInsert = [];

                $malaysia = Country::where('iso2', 'MY')->first();
                $malaysiaStates = State::where('country_id', $malaysia?->id)->get();

                foreach ($venues as $venue) {
                    $state = $malaysiaStates->random();
                    $addressesToInsert[] = [
                        'id' => (string) Str::uuid(),
                        'addressable_type' => 'venue',
                        'addressable_id' => $venue->getKey(),
                        'line1' => fake()->streetAddress(),
                        'line2' => fake()->optional()->words(2, true),
                        'postcode' => fake()->postcode(),
                        'country_id' => $malaysia?->id,
                        'state_id' => $state->id,
                        'lat' => fake()->randomFloat(7, 1.0, 7.0),
                        'lng' => fake()->randomFloat(7, 99.0, 119.0),
                        'google_place_id' => fake()->optional()->numerify('ChI###########'),
                        'waze_url' => fake()->optional()->url(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Bulk insert addresses
                foreach (array_chunk($addressesToInsert, 100) as $chunk) {
                    DB::table('addresses')->insert($chunk);
                }
            });
        } finally {
            Venue::setEventDispatcher(app('events'));
        }
    }
}
