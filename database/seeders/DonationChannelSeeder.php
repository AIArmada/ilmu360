<?php

namespace Database\Seeders;

use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\Speaker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DonationChannelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (DonationChannel::query()->exists()) {
            return;
        }

        DonationChannel::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                // Create donation channels for institutions in bulk
                $institutions = Institution::query()->pluck('status', 'id')->toArray();

                if (empty($institutions)) {
                    $institutions = Institution::factory()->count(3)->create()->pluck('status', 'id')->toArray();
                }

                $donationChannels = [];

                foreach ($institutions as $institutionId => $status) {
                    // Default bank account
                    $donationChannels[] = array_merge(
                        DonationChannel::factory()->bankAccount()->make([
                            'donatable_type' => 'institution',
                            'donatable_id' => $institutionId,
                            'status' => $status === 'verified' ? 'verified' : 'unverified',
                            'is_default' => true,
                        ])->toArray(),
                        ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
                    );

                    // Optionally add DuitNow (60% chance)
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

                    // Optionally add e-wallet (30% chance)
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

                // Create donation channels for some speakers
                $speakers = Speaker::query()->take(5)->pluck('status', 'id')->toArray();

                foreach ($speakers as $speakerId => $status) {
                    if (fake()->boolean(30)) {
                        $donationChannels[] = array_merge(
                            DonationChannel::factory()->bankAccount()->make([
                                'donatable_type' => 'speaker',
                                'donatable_id' => $speakerId,
                                'status' => $status === 'verified' ? 'verified' : 'unverified',
                                'is_default' => true,
                            ])->toArray(),
                            ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
                        );
                    }
                }

                // Bulk insert all donation channels
                foreach (array_chunk($donationChannels, 100) as $chunk) {
                    DonationChannel::insert($chunk);
                }
            });
        } finally {
            DonationChannel::setEventDispatcher(app('events'));
        }
    }
}
