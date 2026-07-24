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

        DonationChannel::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                $institutions = Institution::query()->pluck('status', 'id')->toArray();

                if (empty($institutions)) {
                    $institutions = Institution::factory()->count(3)->create()->pluck('status', 'id')->toArray();
                }

                $donationChannels = [];

                foreach ($institutions as $institutionId => $status) {
                    $donationChannels[] = array_merge(
                        DonationChannel::factory()->bankAccount()->make([
                            'donatable_type' => 'institution',
                            'donatable_id' => $institutionId,
                            'status' => $status === 'verified' ? 'verified' : 'unverified',
                            'is_default' => true,
                        ])->toArray(),
                        ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
                    );

                    if (fake()->boolean(60)) {
                        $donationChannels[] = array_merge(
                            DonationChannel::factory()->duitnow()->make([
                                'donatable_type' => 'institution',
                                'donatable_id' => $institutionId,
                                'status' => $status === 'verified' ? 'verified' : 'unverified',
                            ])->toArray(),
                            ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
                        );
                    }

                    if (fake()->boolean(30)) {
                        $donationChannels[] = array_merge(
                            DonationChannel::factory()->ewallet()->make([
                                'donatable_type' => 'institution',
                                'donatable_id' => $institutionId,
                                'status' => 'unverified',
                            ])->toArray(),
                            ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
                        );
                    }
                }

                $persons = Person::query()->take(5)->pluck('status', 'id')->toArray();

                foreach ($persons as $personId => $status) {
                    if (fake()->boolean(30)) {
                        $donationChannels[] = array_merge(
                            DonationChannel::factory()->bankAccount()->make([
                                'donatable_type' => 'person',
                                'donatable_id' => $personId,
                                'status' => $status === 'verified' ? 'verified' : 'unverified',
                                'is_default' => true,
                            ])->toArray(),
                            ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
                        );
                    }
                }

                foreach (array_chunk($donationChannels, 100) as $chunk) {
                    DonationChannel::insert($chunk);
                }
            });
        } finally {
            DonationChannel::setEventDispatcher(app('events'));
        }
    }
}
