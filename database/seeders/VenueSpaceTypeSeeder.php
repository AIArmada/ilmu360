<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Events\Models\VenueSpaceType;
use Illuminate\Database\Seeder;

final class VenueSpaceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'hall', 'name' => 'Hall', 'category' => 'gathering'],
            ['code' => 'prayer_hall', 'name' => 'Prayer Hall', 'category' => 'worship'],
            ['code' => 'meeting_room', 'name' => 'Meeting Room', 'category' => 'meeting'],
            ['code' => 'lecture_room', 'name' => 'Lecture Room', 'category' => 'learning'],
            ['code' => 'exhibition', 'name' => 'Exhibition Space', 'category' => 'gathering'],
            ['code' => 'banquet_hall', 'name' => 'Banquet Hall', 'category' => 'hospitality'],
            ['code' => 'vip_room', 'name' => 'VIP Room', 'category' => 'hospitality'],
            ['code' => 'reading_room', 'name' => 'Reading Room', 'category' => 'learning'],
            ['code' => 'computer_lab', 'name' => 'Computer Lab', 'category' => 'learning'],
            ['code' => 'library', 'name' => 'Library', 'category' => 'learning'],
            ['code' => 'cafeteria', 'name' => 'Cafeteria', 'category' => 'hospitality'],
            ['code' => 'prayer_room', 'name' => 'Prayer Room', 'category' => 'worship'],
            ['code' => 'ablution_area', 'name' => 'Ablution Area', 'category' => 'worship'],
        ];

        foreach ($types as $sortOrder => $type) {
            VenueSpaceType::query()->updateOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'category' => $type['category'],
                    'sort_order' => $sortOrder * 10,
                    'is_active' => true,
                ],
            );
        }
    }
}
