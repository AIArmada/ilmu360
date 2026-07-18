<?php

namespace Database\Seeders;

use AIArmada\Events\Models\FacilityType;
use Illuminate\Database\Seeder;

class FacilityTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'parking', 'name' => 'Parking', 'sort_order' => 10],
            ['code' => 'oku', 'name' => 'OKU Access', 'sort_order' => 20],
            ['code' => 'women_section', 'name' => 'Women Section', 'sort_order' => 30],
            ['code' => 'ablution_area', 'name' => 'Ablution Area', 'sort_order' => 40],
        ] as $facility) {
            FacilityType::query()->updateOrCreate(
                ['code' => $facility['code']],
                [
                    'name' => $facility['name'],
                    'category' => 'venue',
                    'sort_order' => $facility['sort_order'],
                    'is_active' => true,
                ],
            );
        }
    }
}
