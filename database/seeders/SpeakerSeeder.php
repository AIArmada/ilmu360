<?php

namespace Database\Seeders;

use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SpeakerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Disable model events for faster seeding
        Speaker::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                $this->seedSpeakers();
            });
        } finally {
            Speaker::setEventDispatcher(app('events'));
        }
    }

    private function seedSpeakers(): void
    {
        $realSpeakers = [
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

        // Create real speakers
        foreach ($realSpeakers as $name) {
            $speaker = Speaker::firstOrCreate(
                ['name' => $name],
                [
                    'slug' => Str::slug($name),
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
                    'is_active' => true,
                ]
            );

            $speaker->contacts()->updateOrCreate(
                ['category' => ContactCategory::Email->value],
                ['value' => Str::slug($name).'@example.com', 'type' => ContactType::Work->value]
            );

            $speaker->contacts()->updateOrCreate(
                ['category' => ContactCategory::Phone->value],
                ['value' => $this->deterministicPhoneNumber($name), 'type' => ContactType::Work->value]
            );

            if (! empty($userIds)) {
                $memberAttachments[] = [
                    'speaker_id' => $speaker->id,
                    'user_id' => $userIds[array_rand($userIds)],
                ];
            }
        }

        // Add filler speakers if needed
        $currentCount = Speaker::count();
        if ($currentCount < 30) {
            $speakers = Speaker::factory()->count(30 - $currentCount)->create();

            foreach ($speakers as $speaker) {
                if (! empty($userIds)) {
                    $memberAttachments[] = [
                        'speaker_id' => $speaker->id,
                        'user_id' => $userIds[array_rand($userIds)],
                    ];
                }
            }
        }

        // Bulk insert member attachments
        if ($memberAttachments !== []) {
            DB::table('speaker_user')->insertOrIgnore($memberAttachments);
        }
    }

    private function deterministicPhoneNumber(string $name): string
    {
        $suffix = str_pad((string) (abs(crc32($name)) % 100000000), 8, '0', STR_PAD_LEFT);

        return '01'.$suffix;
    }
}
