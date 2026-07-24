<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReportSeeder extends Seeder
{
    public function run(): void
    {
        if (Report::query()->exists()) {
            return;
        }

        $reporters = User::query()->get();

        if ($reporters->isEmpty()) {
            return;
        }

        $categories = [
            'wrong_info',
            'cancelled_not_updated',
            'fake_person',
            'inappropriate_content',
            'donation_scam',
            'other',
        ];

        $events = Event::query()->take(4)->get();
        foreach ($events as $event) {
            Report::factory()->create([
                'entity_type' => 'event',
                'entity_id' => $event->id,
                'category' => fake()->randomElement($categories),
                'reporter_id' => $reporters->random()->id,
            ]);
        }

        $institutions = Institution::query()->take(2)->get();
        foreach ($institutions as $institution) {
            Report::factory()->create([
                'entity_type' => 'institution',
                'entity_id' => $institution->id,
                'category' => fake()->randomElement($categories),
                'reporter_id' => $reporters->random()->id,
            ]);
        }

        $persons = Person::query()->take(1)->get();
        foreach ($persons as $person) {
            Report::factory()->create([
                'entity_type' => 'person',
                'entity_id' => $person->id,
                'category' => fake()->randomElement($categories),
                'reporter_id' => $reporters->random()->id,
            ]);
        }

        $donationChannels = DonationChannel::query()->take(1)->get();
        foreach ($donationChannels as $donationChannel) {
            Report::factory()->create([
                'entity_type' => 'donation_channel',
                'entity_id' => $donationChannel->id,
                'category' => 'donation_scam',
                'reporter_id' => $reporters->random()->id,
            ]);
        }
    }
}
