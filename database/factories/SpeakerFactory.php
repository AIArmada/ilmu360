<?php

namespace Database\Factories;

use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\State;
use Database\Factories\Concerns\EnsuresMalaysiaCountry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nnjeim\World\Models\Language;

/**
 * @extends Factory<Speaker>
 */
class SpeakerFactory extends Factory
{
    use EnsuresMalaysiaCountry;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $maleFirstNames = [
            'Ahmad',
            'Muhammad',
            'Mohd',
            'Syafiq',
            'Faris',
            'Zaid',
            'Imran',
            'Harith',
            'Irfan',
            'Aiman',
            'Azlan',
            'Haziq',
            'Hakim',
            'Hilmi',
            'Faiz',
            'Iskandar',
            'Khairol',
            'Ridzuan',
            'Zulkifli',
            'Afiq',
            'Azim',
            'Firdaus',
            'Kamal',
            'Nazri',
            'Asyraf',
            'Hafiz',
            'Naufal',
            'Arif',
            'Syahmi',
            'Aqil',
        ];
        $femaleFirstNames = [
            'Nur',
            'Siti',
            'Aisyah',
            'Hannah',
            'Nabila',
            'Sofea',
            'Farah',
            'Atiqah',
            'Zulaikha',
            'Maryam',
            'Amina',
            'Nurin',
            'Syuhada',
            'Alya',
            'Husna',
            'Izzah',
            'Nadia',
            'Sakinah',
            'Raihana',
            'Balqis',
            'Marwa',
            'Asma',
            'Najwa',
            'Mariam',
            'Nadiah',
            'Sofiah',
            'Ain',
            'Irdina',
            'Qistina',
            'Hawa',
        ];
        $maleSecondNames = [
            'Hassan',
            'Husain',
            'Hamzah',
            'Khalid',
            'Yusof',
            'Rahman',
            'Rashid',
            'Salleh',
            'Saifuddin',
            'Syed',
            'Fadhil',
            'Anwar',
            'Zaki',
            'Rafiq',
        ];
        $femaleSecondNames = [
            'Husna',
            'Nabila',
            'Azzahra',
            'Salsabila',
            'Khadijah',
            'Halimah',
            'Amirah',
            'Safiyyah',
            'Ruqayyah',
            'Zainab',
            'Nadhirah',
            'Izzati',
        ];
        $parentNames = [
            'Ismail',
            'Hassan',
            'Rahman',
            'Yusof',
            'Salleh',
            'Mahmud',
            'Hamzah',
            'Zulkifli',
            'Halim',
            'Kamal',
            'Salim',
            'Jaafar',
            'Rashid',
            'Abdullah',
            'Othman',
            'Ibrahim',
            'Khalid',
            'Ariffin',
            'Nasir',
            'Abdul Rahman',
            'Abdul Aziz',
            'Abdul Wahid',
            'Abdul Karim',
        ];
        // Pre-nominals (Professional/Religious titles) - using enum values
        $preNominalsMale = ['ustaz', 'dr', 'prof', 'ir', 'tuan_guru', 'syeikh', 'maulana', 'hj', 'hafiz', 'mufti'];
        $preNominalsFemale = ['ustazah', 'dr', 'prof', 'ir', 'hjh', 'hafizah', 'qariah'];

        // Honorifics (State awards) - using enum values
        $honorificsMale = ['dato', 'datuk', 'tan_sri', 'tun', 'datuk_seri', 'datuk_wira'];
        $honorificsFemale = ['datin', 'datin_paduka', 'puan_sri', 'toh_puan'];

        // Post-nominals (Academic qualifications)
        $postNominals = ['PhD', 'MSc', 'MA', 'BSc', 'BA', 'HONS'];

        $isFemale = fake()->boolean(45);

        // Generate Name
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

        // Populate new fields - honorific can have multiple values
        $honorific = null;
        if (fake()->boolean(10)) {
            $availableHonorifics = $isFemale ? $honorificsFemale : $honorificsMale;
            $count = fake()->numberBetween(1, min(2, count($availableHonorifics)));
            $honorific = fake()->randomElements($availableHonorifics, $count);
        }

        // Pre-nominal can also have multiple values
        $preNominal = null;
        if (fake()->boolean(30)) {
            $availablePreNominals = $isFemale ? $preNominalsFemale : $preNominalsMale;
            $count = fake()->numberBetween(1, min(2, count($availablePreNominals)));
            $preNominal = fake()->randomElements($availablePreNominals, $count);
        }

        // Post-nominal can also have multiple values
        $postNominal = null;
        if (fake()->boolean(20)) {
            $count = fake()->numberBetween(1, min(3, count($postNominals)));
            $postNominal = fake()->randomElements($postNominals, $count);
        }

        $universities = [
            'Universiti Az-Zaitunah',
            'Al-Azhar University',
            'Universiti Islam Madinah',
            'Universiti Malaya',
            'Universiti Kebangsaan Malaysia',
            'Universiti Islam Antarabangsa Malaysia',
            'Universiti Sains Islam Malaysia',
            'Universiti Yarmouk',
            'Kolej Universiti Islam Antarabangsa Selangor',
        ];

        $degrees = ['Bachelor', 'Masters', 'PhD', 'Diploma'];
        $fields = ['Syariah', 'Usuluddin', 'Dakwah', 'Islamic Finance', 'Fiqh Fatwa', 'Tafsir', 'Hadith'];

        $qualifications = [];
        if (fake()->boolean(70)) {
            $count = fake()->numberBetween(1, 3);
            for ($i = 0; $i < $count; $i++) {
                $qualifications[] = [
                    'institution' => fake()->randomElement($universities),
                    'degree' => fake()->randomElement($degrees),
                    'field' => fake()->randomElement($fields),
                    'year' => fake()->numberBetween(1990, 2023),
                ];
            }
        }

        return [
            'name' => $name,
            'gender' => $isFemale ? 'female' : 'male',
            'honorific' => $honorific,
            'pre_nominal' => $preNominal,
            'post_nominal' => $postNominal,
            'is_freelance' => fake()->boolean(20),
            'qualifications' => $qualifications,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(7)),
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
            'is_active' => true,
        ];
    }

    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function (Speaker $speaker) {
            $malaysia = $this->ensureMalaysiaCountry();

            // Create Address
            $state = State::inRandomOrder()->first();
            $speaker->address()->create([
                'country_id' => (int) ($state->country_id ?? $malaysia->getKey()),
                'state_id' => $state?->id,
                'district_id' => $state?->districts()->inRandomOrder()->first()?->id,
            ]);

            $speaker->contacts()->create([
                'category' => ContactCategory::Email->value,
                'value' => fake()->safeEmail(),
                'type' => ContactType::Work->value,
            ]);

            $speaker->contacts()->create([
                'category' => ContactCategory::Phone->value,
                'value' => fake()->phoneNumber(),
                'type' => ContactType::Work->value,
            ]);

            // Attach Languages
            if (class_exists(Language::class)) {
                $languages = Language::inRandomOrder()->limit(random_int(1, 3))->pluck('id');
                $speaker->languages()->attach($languages);
            }

            // Attach Institutions
            if (! $speaker->is_freelance) {
                $institutions = Institution::inRandomOrder()->limit(random_int(1, 2))->get();
                foreach ($institutions as $institution) {
                    $speaker->institutions()->attach($institution->id, [
                        'position' => fake()->randomElement(['Imam', 'Lecturer', 'Guest Speaker', 'Advisor']),
                        'is_primary' => fake()->boolean(30),
                        'joined_at' => fake()->date(),
                    ]);
                }
            } else {
                $speaker->update([
                    'job_title' => fake()->randomElement(['Freelance Da\'i', 'Independent Scholar', 'Religious Columnist', 'Motivation Speaker']),
                ]);
            }

            $speaker->refresh();
        });
    }
}
