<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\VenueSpaceType;
use App\Models\Space;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SpaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([VenueSpaceTypeSeeder::class]);

        // Create common spaces that can be offered to all institutions
        // Submitters will select which spaces are relevant when creating events
        $commonSpaces = [
            ['name' => 'Dewan Utama', 'capacity' => 500, 'space_type' => 'hall'],
            ['name' => 'Dewan Solat Lelaki', 'capacity' => 300, 'space_type' => 'prayer_hall'],
            ['name' => 'Dewan Solat Wanita', 'capacity' => 200, 'space_type' => 'prayer_hall'],
            ['name' => 'Dewan Serbaguna', 'capacity' => 400, 'space_type' => 'hall'],
            ['name' => 'Bilik Mesyuarat A', 'capacity' => 30, 'space_type' => 'meeting_room'],
            ['name' => 'Bilik Mesyuarat B', 'capacity' => 20, 'space_type' => 'meeting_room'],
            ['name' => 'Bilik Mesyuarat C', 'capacity' => 20, 'space_type' => 'meeting_room'],
            ['name' => 'Bilik Kuliah 1', 'capacity' => 50, 'space_type' => 'lecture_room'],
            ['name' => 'Bilik Kuliah 2', 'capacity' => 50, 'space_type' => 'lecture_room'],
            ['name' => 'Bilik Kuliah 3', 'capacity' => 50, 'space_type' => 'lecture_room'],
            ['name' => 'Ruang Pameran', 'capacity' => 100, 'space_type' => 'exhibition'],
            ['name' => 'Dewan Jamuan', 'capacity' => 200, 'space_type' => 'banquet_hall'],
            ['name' => 'Bilik VIP', 'capacity' => 15, 'space_type' => 'vip_room'],
            ['name' => 'Ruang Bacaan', 'capacity' => 40, 'space_type' => 'reading_room'],
            ['name' => 'Makmal Komputer', 'capacity' => 30, 'space_type' => 'computer_lab'],
            ['name' => 'Perpustakaan', 'capacity' => 60, 'space_type' => 'library'],
            ['name' => 'Kafeteria', 'capacity' => 150, 'space_type' => 'cafeteria'],
            ['name' => 'Surau', 'capacity' => 80, 'space_type' => 'prayer_room'],
            ['name' => 'Ruang Wuduk Lelaki', 'capacity' => 20, 'space_type' => 'ablution_area'],
            ['name' => 'Ruang Wuduk Wanita', 'capacity' => 20, 'space_type' => 'ablution_area'],
        ];

        foreach ($commonSpaces as $spaceData) {
            Space::query()->updateOrCreate([
                'name' => $spaceData['name'],
                'venue_id' => null,
            ], [
                'slug' => Str::slug($spaceData['name']),
                'capacity' => $spaceData['capacity'],
                'space_type' => $spaceData['space_type'],
                'status' => 'active',
                'visibility' => 'public',
            ]);
        }

        $typeIds = VenueSpaceType::query()->pluck('id', 'code');

        EventLocation::query()
            ->whereNotNull('venue_space_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('space_name_snapshot')
                    ->orWhereNull('venue_space_type_id');
            })
            ->with('venueSpace:id,name,space_type')
            ->chunkById(100, function ($locations) use ($typeIds): void {
                foreach ($locations as $location) {
                    $code = $location->venueSpace?->space_type;
                    $typeId = is_string($code) ? $typeIds->get($code) : null;
                    $updates = [];

                    if ($typeId !== null && (string) $location->venue_space_type_id !== (string) $typeId) {
                        $updates['venue_space_type_id'] = $typeId;
                    }

                    if ($location->space_name_snapshot === null && $location->venueSpace?->name !== null) {
                        $updates['space_name_snapshot'] = $location->venueSpace->name;
                    }

                    if ($updates !== []) {
                        $location->forceFill($updates)->saveQuietly();
                    }
                }
            });
    }
}
