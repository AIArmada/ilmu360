<?php

namespace Database\Factories;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Institution>
 */
class InstitutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['masjid', 'surau', 'madrasah']);
        $arabicNames = [
            'Al-Ikhlas',
            'Al-Amin',
            'An-Nur',
            'As-Salam',
            'At-Taqwa',
            'Al-Hidayah',
            'Al-Falah',
            'Al-Muttaqin',
            'Ar-Rahman',
            'Al-Munawwarah',
            'Al-Ansar',
            'Al-Mukminin',
            'Al-Azhar',
            'Al-Kauthar',
            'Al-Istiqamah',
        ];
        $locations = [
            'Taman Melawati',
            'Taman Daya',
            'Taman Universiti',
            'Taman Tun Dr Ismail',
            'Bandar Baru Bangi',
            'Setia Alam',
            'Kota Damansara',
            'Shah Alam',
            'Gombak',
            'Putrajaya',
            'Cyberjaya',
            'Wangsa Maju',
            'Seri Kembangan',
            'Kajang',
            'Rawang',
            'Ampang',
            'Cheras',
            'Subang Jaya',
            'Klang',
            'Batu Caves',
            'Sungai Buloh',
            'Puchong',
            'Senawang',
            'Melaka Tengah',
            'Johor Bahru',
        ];
        $arabicName = fake()->randomElement($arabicNames);
        $location = fake()->randomElement($locations);

        $masjidNames = [
            'Masjid Jamek Kampung Baru',
            'Masjid Sultan Salahuddin Abdul Aziz Shah',
            'Masjid Wilayah Persekutuan',
            'Masjid Putra',
            'Masjid Tuanku Mizan Zainal Abidin',
            'Masjid Negeri',
            'Masjid '.$arabicName,
            'Masjid '.$arabicName.' '.$location,
            'Masjid Jamek '.$location,
            'Masjid '.$location,
        ];
        $surauNames = [
            'Surau '.$arabicName,
            'Surau '.$arabicName.' '.$location,
            'Surau '.$location,
            'Surau Al-Ikhlas '.$location,
            'Surau An-Nur '.$location,
        ];
        $otherNames = [
            'Pusat Islam '.$location,
            'Madrasah '.$arabicName,
            'Maahad Tahfiz '.$location,
            'Kompleks Islam '.$location,
            'Markaz Tarbiah '.$location,
            'Akademi Tahfiz '.$arabicName,
        ];

        $name = match ($type) {
            'masjid' => fake()->randomElement($masjidNames),
            'surau' => fake()->randomElement($surauNames),
            'madrasah' => fake()->randomElement($otherNames),
            default => fake()->randomElement($otherNames),
        };

        return [
            'type' => $type,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(7)),
            'description' => fake()->optional()->paragraph(),
            'status' => 'verified',
        ];
    }

    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function (Institution $institution) {
            OwnerContext::withOwner(null, function () use ($institution): void {
                $country = AddressCountry::query()->firstOrCreate(
                    ['iso2' => 'MY'],
                    ['name' => 'Malaysia', 'iso3' => 'MYS', 'region' => 'Asia', 'subregion' => 'South-Eastern Asia', 'phone_code' => '60'],
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
                $institution->attachAddress($address, 'primary', true);

                $institution->contactMethods()->create([
                    'type' => ContactMethodType::Email->value,
                    'value' => fake()->safeEmail(),
                    'purpose' => ContactPurpose::General->value,
                ]);

                $institution->contactMethods()->create([
                    'type' => ContactMethodType::Phone->value,
                    'value' => fake()->phoneNumber(),
                    'purpose' => ContactPurpose::General->value,
                ]);

                $institution->refresh();
            });
        });
    }
}
