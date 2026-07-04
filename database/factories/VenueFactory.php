<?php

namespace Database\Factories;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Events\Database\Factories\VenueFactory as PackageVenueFactory;
use App\Models\Venue;
use Illuminate\Support\Str;

class VenueFactory extends PackageVenueFactory
{
    protected $model = Venue::class;

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
            'venue_type' => fake()->randomElement([
                'dewan',
                'auditorium',
                'stadium',
                'perpustakaan',
                'padang',
                'hotel',
            ]),
            'status' => 'verified',
            'visibility' => 'public',
            'is_active' => true,
            'city' => fake()->city(),
            // @phpstan-ignore-next-line Faker dynamic provider method
            'state' => fake()->state(),
            'postcode' => fake()->postcode(),
            'country_code' => 'MY',
            'latitude' => fake()->randomFloat(7, 1.0, 7.0),
            'longitude' => fake()->randomFloat(7, 99.0, 119.0),
        ];
    }

    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function ($venue): void {
            if (! $venue instanceof Venue) {
                return;
            }

            $country = AddressCountry::query()->firstOrCreate(
                ['iso2' => 'MY'],
                ['name' => 'Malaysia', 'iso3' => 'MYS', 'entity_type' => 'country', 'region' => 'Asia', 'subregion' => 'South-Eastern Asia', 'timezones' => ['Asia/Kuala_Lumpur'], 'phone_code' => '60'],
            );
            $address = Address::create([
                'country_id' => (string) $country->getKey(),
                'country_code' => 'MY',
                'line1' => fake()->streetAddress(),
                'line2' => fake()->optional()->words(2, true),
                'postcode' => fake()->postcode(),
                'city' => fake()->city(),
                // @phpstan-ignore-next-line Faker dynamic provider method
                'state' => fake()->state(),
                'latitude' => fake()->randomFloat(7, 1.0, 7.0),
                'longitude' => fake()->randomFloat(7, 99.0, 119.0),
            ]);

            $venue->attachAddress($address, 'primary', true);
            $venue->refresh();
        });
    }
}
