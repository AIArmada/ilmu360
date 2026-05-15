<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\MediaLink;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MediaLinkSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (MediaLink::query()->exists()) {
            return;
        }

        MediaLink::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                $eventIds = Event::query()->pluck('id')->toArray();

                $mediaToInsert = [];

                foreach ($eventIds as $eventId) {
                    // Always create primary media
                    $mediaToInsert[] = array_merge(
                        MediaLink::factory()->make([
                            'mediable_type' => 'event',
                            'mediable_id' => $eventId,
                            'is_primary' => true,
                        ])->toArray(),
                        ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
                    );

                    // 50% chance for secondary media
                    if (random_int(0, 1) === 1) {
                        $mediaToInsert[] = array_merge(
                            MediaLink::factory()->make([
                                'mediable_type' => 'event',
                                'mediable_id' => $eventId,
                                'is_primary' => false,
                            ])->toArray(),
                            ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
                        );
                    }
                }

                // Bulk insert
                foreach (array_chunk($mediaToInsert, 200) as $chunk) {
                    MediaLink::insert($chunk);
                }
            });
        } finally {
            MediaLink::setEventDispatcher(app('events'));
        }
    }
}
