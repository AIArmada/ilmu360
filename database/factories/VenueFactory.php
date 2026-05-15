<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\State;
use App\Models\Venue;
use Database\Factories\Concerns\EnsuresMalaysiaCountry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    use EnsuresMalaysiaCountry;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Dewan Utama',
            'Dewan Serbaguna',
            'Dewan Solat Utama',
            'Dewan Al-Ikhlas',
            'Dewan An-Nur',
            'Dewan Al-Amin',
            'Balai Islam',
            'Anjung Ilmu',
            'Ruang Serbaguna',
            'Dewan Kuliah',
            'Dewan Seminar',
        ]);
        $slug = Str::slug($name).'-'.Str::lower(Str::random(7));

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => fake()->paragraph(),
            'type' => fake()->randomElement([
                'dewan',
                'auditorium',
                'stadium',
                'perpustakaan',
                'padang',
                'hotel',
            ]),
            'facilities' => [
                'parking' => fake()->boolean(),
                'oku' => fake()->boolean(),
                'women_section' => fake()->boolean(),
                'ablution_area' => fake()->boolean(),
            ],
            'status' => 'verified',
            'is_active' => true,
        ];
    }

    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function (Venue $venue) {
            $malaysia = Country::where('iso2', 'MY')->first() ?? $this->ensureMalaysiaCountry();
            $state = State::where('country_id', $malaysia->id)->inRandomOrder()->first();

            $venue->address()->create([
                'line1' => fake()->streetAddress(),
                'line2' => fake()->optional()->words(2, true),
                'postcode' => fake()->postcode(),
                'country_id' => $malaysia->id,
                'state_id' => $state?->id,
                'lat' => fake()->randomFloat(7, 1.0, 7.0),
                'lng' => fake()->randomFloat(7, 99.0, 119.0),
                'google_place_id' => fake()->optional()->numerify('ChI###########'),
                'waze_url' => fake()->optional()->url(),
            ]);

            $venue->refresh();
        });
    }
}
