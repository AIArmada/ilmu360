<?php

namespace Database\Factories;

use App\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Space>
 */
class SpaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $prefix = fake()->randomElement(['Bilik', 'Ruang', 'Sudut', 'Anjung', 'Pejabat', 'Pusat']);
        $suffix = fake()->randomElement([
            'Solat Utama',
            'Solat Muslimah',
            'Mesyuarat',
            'Kuliah',
            'Parkir',
            'Pentadbiran',
            'Jamuan',
            'Legar',
            'Bacaan',
            'VVIP',
            'Imam',
            'Bilal',
            'IT',
            'Rakaman',
            'Rehat',
            'Gerakan',
            'Pameran',
            'Pusat Sumber',
            'Kafeteria',
            'Wuduk Lelaki',
            'Wuduk Wanita',
            'Al-Quran',
            'Al-Hadith',
            'Al-Falah',
            'Al-Hidayah',
        ]);

        $name = fake()->unique()->regexify($prefix.' '.$suffix.' (Alpha|Beta|Gamma|A|B|C|1|2|3)');

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(7)),
            'capacity' => fake()->randomElement([10, 20, 30, 50, 100, 200]),
            'is_active' => true,
        ];
    }
}
