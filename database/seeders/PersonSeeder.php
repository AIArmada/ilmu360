<?php

namespace Database\Seeders;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
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
        $realPersons = [
            'Ustaz Azhar Idrus',
            'Dr. MAZA (Dr. Mohd Asri Zainul Abidin)',
            'Ustaz Wadi Annuar',
            'Ustaz Don Daniyal',
            'Habib Ali Zaenal Abidin',
            'Ustaz Kazim Elias',
            'Ustaz Ebit Lew',
            'Dr. Rozaimi Ramle',
            'Ustaz Auni Mohamed',
            'Ustaz Fawwaz Mat Jan',
            'Ustaz Jafri Abu Bakar',
            'Ustaz Abdullah Khairi',
            'Ustaz Haslin Baharim (Bollywood)',
            'Ustaz Syamsul Debat',
            'Prof. Dr. Muhaya Mohamad',
        ];

        $userIds = User::query()->pluck('id')->toArray();
        $memberAttachments = [];

        foreach ($realPersons as $name) {
            OwnerContext::withOwner(null, function () use ($name, $userIds, &$memberAttachments): void {
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

                if ($person->wasRecentlyCreated || $person->speaker_status === null) {
                    $person->forceFill(['speaker_status' => SpeakerStatus::Active->value])->saveQuietly();
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
