<?php

namespace Database\Factories;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Persons\Enums\AffiliationType;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Enums\PersonNameType;
use AIArmada\Persons\Models\Affiliation;
use AIArmada\Persons\Models\PersonName;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        $maleFirstNames = [
            'Ahmad', 'Muhammad', 'Mohd', 'Syafiq', 'Faris', 'Zaid', 'Imran',
            'Harith', 'Irfan', 'Aiman', 'Azlan', 'Haziq', 'Hakim', 'Hilmi',
            'Faiz', 'Iskandar', 'Khairol', 'Ridzuan', 'Zulkifli', 'Afiq',
            'Azim', 'Firdaus', 'Kamal', 'Nazri', 'Asyraf', 'Hafiz', 'Naufal',
            'Arif', 'Syahmi', 'Aqil',
        ];
        $femaleFirstNames = [
            'Nur', 'Siti', 'Aisyah', 'Hannah', 'Nabila', 'Sofea', 'Farah',
            'Atiqah', 'Zulaikha', 'Maryam', 'Amina', 'Nurin', 'Syuhada',
            'Alya', 'Husna', 'Izzah', 'Nadia', 'Sakinah', 'Raihana', 'Balqis',
            'Marwa', 'Asma', 'Najwa', 'Mariam', 'Nadiah', 'Sofiah', 'Ain',
            'Irdina', 'Qistina', 'Hawa',
        ];
        $maleSecondNames = [
            'Hassan', 'Husain', 'Hamzah', 'Khalid', 'Yusof', 'Rahman',
            'Rashid', 'Salleh', 'Saifuddin', 'Syed', 'Fadhil', 'Anwar',
            'Zaki', 'Rafiq',
        ];
        $femaleSecondNames = [
            'Husna', 'Nabila', 'Azzahra', 'Salsabila', 'Khadijah', 'Halimah',
            'Amirah', 'Safiyyah', 'Ruqayyah', 'Zainab', 'Nadhirah', 'Izzati',
        ];
        $parentNames = [
            'Ismail', 'Hassan', 'Rahman', 'Yusof', 'Salleh', 'Mahmud',
            'Hamzah', 'Zulkifli', 'Halim', 'Kamal', 'Salim', 'Jaafar',
            'Rashid', 'Abdullah', 'Othman', 'Ibrahim', 'Khalid', 'Ariffin',
            'Nasir', 'Abdul Rahman', 'Abdul Aziz', 'Abdul Wahid', 'Abdul Karim',
        ];

        $isFemale = fake()->boolean(45);

        $firstName = $isFemale
            ? fake()->randomElement($femaleFirstNames)
            : fake()->randomElement($maleFirstNames);
        $secondName = fake()->boolean(65)
            ? fake()->randomElement($isFemale ? $femaleSecondNames : $maleSecondNames)
            : null;
        $givenName = trim(implode(' ', array_filter([$firstName, $secondName])));
        $connector = $isFemale ? 'binti' : 'bin';
        $parentName = fake()->randomElement($parentNames);
        $name = $givenName.' '.$connector.' '.$parentName;

        return [
            'name' => $name,
            'family_name' => $parentName,
            'gender' => $isFemale ? Gender::Female->value : Gender::Male->value,
            'date_of_birth' => fake()->optional(0.6)->date(max: 'now -18 years'),
            'nationality_country_id' => null,
            'slug' => (string) Str::uuid(),
            'bio' => fake()->boolean(70)
                ? [
                    'type' => 'doc',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => fake()->paragraph(),
                        ]],
                    ]],
                ]
                : null,
            'status' => 'verified',
        ];
    }

    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function (Person $person) {
            OwnerContext::withOwner(null, function () use ($person): void {
                $country = AddressCountry::query()->firstOrCreate(
                    ['iso2' => 'MY'],
                    ['name' => 'Malaysia', 'iso3' => 'MYS', 'entity_type' => 'country', 'region' => 'Asia', 'subregion' => 'South-Eastern Asia', 'timezones' => ['Asia/Kuala_Lumpur'], 'phone_code' => '60'],
                );
                $address = Address::create([
                    'country_id' => (string) $country->getKey(),
                    'country_code' => 'MY',
                    'city' => fake()->city(),
                    // @phpstan-ignore-next-line Faker dynamic provider method
                    'state' => fake()->state(),
                ]);
                $person->attachAddress($address, 'primary', true);

                $person->refresh();
                $person->forceFill([
                    'slug' => app(GeneratePersonSlugAction::class)->forPerson($person),
                ])->saveQuietly();

                $person->contactMethods()->create([
                    'type' => ContactMethodType::Email->value,
                    'value' => fake()->safeEmail(),
                    'purpose' => ContactPurpose::General->value,
                ]);

                $person->contactMethods()->create([
                    'type' => ContactMethodType::Phone->value,
                    'value' => fake()->phoneNumber(),
                    'purpose' => ContactPurpose::General->value,
                ]);

                if (class_exists(Language::class)) {
                    $languages = Language::inRandomOrder()->limit(random_int(1, 3))->pluck('id');
                    $person->languages()->attach($languages);
                }

                $person->refresh();

                // Create a display name record
                PersonName::create([
                    'person_id' => $person->id,
                    'name_type' => PersonNameType::Display,
                    'full_name' => $person->name,
                    'language_code' => 'ms',
                    'is_primary' => true,
                ]);

                // Assign random titles
                $titles = Title::inRandomOrder()->limit(random_int(0, 3))->get();
                foreach ($titles as $title) {
                    TitleAssignment::create([
                        'titleable_type' => Person::class,
                        'titleable_id' => $person->id,
                        'title_id' => $title->id,
                        'date_awarded' => fake()->optional()->date(),
                        'status' => AssignmentStatus::Active,
                    ]);
                }

                // Attach to institutions via affiliations
                $isFreelance = fake()->boolean(20);
                if (! $isFreelance) {
                    $institutions = Institution::inRandomOrder()->limit(random_int(1, 2))->get();
                    foreach ($institutions as $institution) {
                        Affiliation::create([
                            'affiliatable_type' => Person::class,
                            'affiliatable_id' => $person->id,
                            'institution_id' => $institution->id,
                            'affiliation_type' => fake()->randomElement([AffiliationType::Member, AffiliationType::Employee, AffiliationType::Advisor]),
                            'joined_at' => fake()->optional()->date(),
                            'is_primary' => false,
                        ]);
                    }
                }
            });
        });
    }
}
