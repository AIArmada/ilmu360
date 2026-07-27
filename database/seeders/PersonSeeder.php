<?php

namespace Database\Seeders;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Models\Title;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Enums\SpeakerStatus;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonSeeder extends Seeder
{
    public function run(): void
    {
        Person::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                $this->seedPersons();
            });
        } finally {
            Person::setEventDispatcher(app('events'));
        }
    }

    private function seedPersons(): void
    {
        $malaysia = AddressCountry::query()->firstOrCreate(
            ['iso2' => 'MY'],
            [
                'name' => 'Malaysia',
                'iso3' => 'MYS',
                'region' => 'Asia',
                'subregion' => 'South-Eastern Asia',
                'phone_code' => '60',
            ],
        );

        $realPersons = [
            ['name' => 'Azhar Idrus', 'titles' => ['Ustaz']],
            ['name' => 'Mohd Asri Zainul Abidin', 'titles' => ['Dr.']],
            ['name' => 'Wadi Annuar', 'titles' => ['Ustaz']],
            ['name' => 'Don Daniyal', 'titles' => ['Ustaz']],
            ['name' => 'Ali Zaenal Abidin', 'titles' => ['Habib']],
            ['name' => 'Kazim Elias', 'titles' => ['Ustaz']],
            ['name' => 'Ebit Lew', 'titles' => ['Ustaz']],
            ['name' => 'Rozaimi Ramle', 'titles' => ['Dr.']],
            ['name' => 'Auni Mohamed', 'titles' => ['Ustaz']],
            ['name' => 'Fawwaz Mat Jan', 'titles' => ['Ustaz']],
            ['name' => 'Jafri Abu Bakar', 'titles' => ['Ustaz']],
            ['name' => 'Abdullah Khairi', 'titles' => ['Ustaz']],
            ['name' => 'Haslin Baharim (Bollywood)', 'titles' => ['Ustaz']],
            ['name' => 'Syamsul Debat', 'titles' => ['Ustaz']],
            ['name' => 'Muhaya Mohamad', 'titles' => ['Profesor', 'Dr.']],
        ];

        $userIds = User::query()->pluck('id')->toArray();
        $memberAttachments = [];

        foreach ($realPersons as $personData) {
            OwnerContext::withOwner(null, function () use ($personData, $userIds, $malaysia, &$memberAttachments): void {
                $name = $personData['name'];
                $person = Person::firstOrCreate(
                    ['name' => $name],
                    [
                        'slug' => app(GeneratePersonSlugAction::class)->handle($name),
                        'bio' => [
                            'type' => 'doc',
                            'content' => [[
                                'type' => 'paragraph',
                                'content' => [[
                                    'type' => 'text',
                                    'text' => fake()->paragraph(),
                                ]],
                            ]],
                        ],
                        'status' => 'verified',
                        'speaker_status' => SpeakerStatus::Active->value,
                    ]
                );

                if ($person->wasRecentlyCreated || $person->getAttribute('speaker_status') === null) {
                    $person->forceFill(['speaker_status' => SpeakerStatus::Active->value])->saveQuietly();
                }

                if ($person->wasRecentlyCreated) {
                    $person->attachAddress(Address::query()->create([
                        'country_id' => $malaysia->getKey(),
                        'country_code' => 'MY',
                        'country' => 'Malaysia',
                    ]), 'primary', true);
                }

                foreach ($personData['titles'] as $titleName) {
                    $title = Title::query()->where('name', $titleName)->firstOrFail();

                    $person->titleAssignments()->firstOrCreate(
                        ['title_id' => $title->id],
                        ['status' => AssignmentStatus::Active],
                    );
                }

                $person->contactMethods()->updateOrCreate(
                    ['type' => ContactMethodType::Email->value],
                    ['value' => Str::slug($name).'@example.com', 'purpose' => ContactPurpose::General->value]
                );

                $person->contactMethods()->updateOrCreate(
                    ['type' => ContactMethodType::Phone->value],
                    ['value' => $this->deterministicPhoneNumber($name), 'purpose' => ContactPurpose::General->value]
                );

                if (! empty($userIds)) {
                    $memberAttachments[] = [
                        'person_id' => $person->id,
                        'user_id' => $userIds[array_rand($userIds)],
                    ];
                }
            });
        }

        $currentCount = Person::count();
        if ($currentCount < 30) {
            $persons = Person::factory()->count(30 - $currentCount)->create();

            foreach ($persons as $person) {
                if (! empty($userIds)) {
                    $memberAttachments[] = [
                        'person_id' => $person->id,
                        'user_id' => $userIds[array_rand($userIds)],
                    ];
                }
            }
        }

        if ($memberAttachments !== []) {
            DB::table('person_members')->insertOrIgnore($memberAttachments);
        }
    }

    private function deterministicPhoneNumber(string $name): string
    {
        $suffix = str_pad((string) (abs(crc32($name)) % 100000000), 8, '0', STR_PAD_LEFT);

        return '01'.$suffix;
    }
}
