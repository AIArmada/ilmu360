<?php

declare(strict_types=1);

namespace Database\Seeders;

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
        // Create common spaces that can be offered to all institutions
        // Submitters will select which spaces are relevant when creating events
        $commonSpaces = [
            ['name' => 'Dewan Utama', 'capacity' => 500],
            ['name' => 'Dewan Solat Lelaki', 'capacity' => 300],
            ['name' => 'Dewan Solat Wanita', 'capacity' => 200],
            ['name' => 'Dewan Serbaguna', 'capacity' => 400],
            ['name' => 'Bilik Mesyuarat A', 'capacity' => 30],
            ['name' => 'Bilik Mesyuarat B', 'capacity' => 20],
            ['name' => 'Bilik Mesyuarat C', 'capacity' => 20],
            ['name' => 'Bilik Kuliah 1', 'capacity' => 50],
            ['name' => 'Bilik Kuliah 2', 'capacity' => 50],
            ['name' => 'Bilik Kuliah 3', 'capacity' => 50],
            ['name' => 'Ruang Pameran', 'capacity' => 100],
            ['name' => 'Dewan Jamuan', 'capacity' => 200],
            ['name' => 'Bilik VIP', 'capacity' => 15],
            ['name' => 'Ruang Bacaan', 'capacity' => 40],
            ['name' => 'Makmal Komputer', 'capacity' => 30],
            ['name' => 'Perpustakaan', 'capacity' => 60],
            ['name' => 'Kafeteria', 'capacity' => 150],
            ['name' => 'Surau', 'capacity' => 80],
            ['name' => 'Ruang Wuduk Lelaki', 'capacity' => 20],
            ['name' => 'Ruang Wuduk Wanita', 'capacity' => 20],
        ];

        foreach ($commonSpaces as $spaceData) {
            Space::query()->updateOrCreate([
                'name' => $spaceData['name'],
            ], [
                'slug' => Str::slug($spaceData['name']),
                'capacity' => $spaceData['capacity'],
                'is_active' => true,
            ]);
        }
    }
}
