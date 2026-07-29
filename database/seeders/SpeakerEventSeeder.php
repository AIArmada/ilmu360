<?php

namespace Database\Seeders;

use App\Enums\EventFormat;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

final class SpeakerEventSeeder extends Seeder
{
    public function run(): void
    {
        $institution = Institution::query()->where('status', 'verified')->orderBy('id')->first();

        if (! $institution instanceof Institution) {
            return;
        }

        $speakerNames = [
            'Wadi Annuar',
            'Rozaimi Ramle',
        ];

        foreach ($speakerNames as $speakerName) {
            $speaker = Person::query()
                ->where('name', $speakerName)
                ->where('status', 'verified')
                ->first();

            if (! $speaker instanceof Person) {
                continue;
            }

            foreach (range(1, 4) as $sequence) {
                $startsAt = Carbon::now()
                    ->subMonths($sequence + 1)
                    ->setTime(20, 0);
                $slug = 'sejarah-'.$speaker->slug.'-'.$sequence;

                $event = Event::query()->where('slug', $slug)->first();

                if (! $event instanceof Event) {
                    $event = Event::factory()->create([
                        'institution_id' => $institution->getKey(),
                        'default_venue_id' => null,
                        'title' => 'Kuliah Sejarah Bersama '.$speaker->name.' '.$sequence,
                        'description' => 'Acara sejarah seeded untuk menguji senarai majlis terdahulu penceramah.',
                        'starts_at' => $startsAt,
                        'ends_at' => $startsAt->copy()->addHours(2),
                        'timezone' => 'Asia/Kuala_Lumpur',
                        'delivery_mode' => EventFormat::Physical->value,
                        'visibility' => EventVisibility::Public->value,
                        'status' => 'approved',
                        'published_at' => $startsAt->copy()->subDays(7),
                        'slug' => $slug,
                    ]);
                }

                EventKeyPerson::query()->firstOrCreate(
                    [
                        'event_id' => $event->getKey(),
                        'involveable_type' => $speaker->getMorphClass(),
                        'involveable_id' => $speaker->getKey(),
                        'role_code' => EventKeyPersonRole::Speaker->value,
                    ],
                    [
                        'status' => 'active',
                        'visibility' => 'public',
                        'prominence' => '0',
                        'is_featured' => false,
                        'is_primary' => true,
                        'sort_order' => 1,
                    ],
                );
            }
        }
    }
}
