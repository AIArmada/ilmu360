<?php

namespace Database\Seeders;

use AIArmada\Persons\Models\TitleCategory;
use Illuminate\Database\Seeder;

class TitleCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'state_honour', 'name' => 'State Honour', 'sort_order' => 10],
            ['code' => 'royal', 'name' => 'Royal Title', 'sort_order' => 20],
            ['code' => 'religious', 'name' => 'Religious Title', 'sort_order' => 30],
            ['code' => 'academic', 'name' => 'Academic Title', 'sort_order' => 40],
            ['code' => 'professional', 'name' => 'Professional Title', 'sort_order' => 50],
            ['code' => 'military', 'name' => 'Military Rank', 'sort_order' => 60],
            ['code' => 'social', 'name' => 'Social Honorific', 'sort_order' => 70],
        ];

        foreach ($categories as $category) {
            TitleCategory::firstOrCreate(
                ['code' => $category['code']],
                $category
            );
        }
    }
}
