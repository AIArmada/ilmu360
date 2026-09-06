<?php

namespace Database\Seeders;

use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\Person;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DonationChannelSeeder extends Seeder
{
    public function run(): void
    {
        if (DonationChannel::query()->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $institutions = Institution::query()->pluck('status', 'id')->toArray();

            if (empty($institutions)) {
                $institutions = Institution::factory()->count(3)->create()->pluck('status', 'id')->toArray();
            }

            $donationChannels = [];

            foreach ($institutions as $institutionId => $status) {
                $donationChannels[] = $this->withId(DonationChannel::factory()->bankAccount()->make([
                    'donatable_type' => 'institution',
                    'donatable_id' => $institutionId,
                    'status' => $status === 'verified' ? 'verified' : 'pending',
                    'is_default' => true,
                ]));

                if (fake()->boolean(60)) {
                    $donationChannels[] = $this->withId(DonationChannel::factory()->duitnow()->make([
                        'donatable_type' => 'institution',
                        'donatable_id' => $institutionId,
                        'status' => $status === 'verified' ? 'verified' : 'pending',
                    ]));
                }

                if (fake()->boolean(30)) {
                    $donationChannels[] = $this->withId(DonationChannel::factory()->ewallet()->make([
                        'donatable_type' => 'institution',
                        'donatable_id' => $institutionId,
                        'status' => 'pending',
                    ]));
                }
            }

            $persons = Person::query()->take(5)->pluck('status', 'id')->toArray();

            foreach ($persons as $personId => $status) {
                if (fake()->boolean(30)) {
                    $donationChannels[] = $this->withId(DonationChannel::factory()->bankAccount()->make([
                        'donatable_type' => 'person',
                        'donatable_id' => $personId,
                        'status' => $status === 'verified' ? 'verified' : 'pending',
                        'is_default' => true,
                    ]));
                }
            }

            foreach (array_chunk($donationChannels, 100) as $chunk) {
                foreach ($chunk as $donationChannel) {
                    $donationChannel->saveQuietly();
                }
            }
        });
    }

    private function withId(DonationChannel $channel): DonationChannel
    {
        $channel->id = (string) Str::uuid();

        return $channel;
    }
}
