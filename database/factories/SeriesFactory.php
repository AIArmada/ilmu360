<?php

namespace Database\Factories;

use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Series>
 */
class SeriesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $topics = [
            'Tafsir Al-Fatihah',
            'Tafsir Al-Kahfi',
            'Fiqh Solat',
            'Fiqh Muamalat',
            'Sirah Nabawiyyah',
            'Hadis Arba\'in',
            'Akhlak & Adab',
            'Tazkiyah An-Nafs',
            'Keluarga Sakinah',
            'Tarbiah Remaja',
        ];
        $books = [
            'Riyadus Salihin',
            'Bulughul Maram',
            'Al-Arba\'in An-Nawawiyyah',
            'Tafsir Ibnu Kathir',
            'Fiqh Manhaji',
        ];

        $topic = fake()->randomElement($topics);
        $book = fake()->randomElement($books);
        $title = fake()->randomElement([
            'Siri Tafsir '.$topic,
            'Halaqah Kitab '.$book,
            'Kelas Fiqh '.$topic,
            'Siri Sirah '.$topic,
            'Kuliah Kitab '.$book,
            'Daurah '.$topic,
        ]);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(7)),
            'description' => fake()->optional()->paragraph(),
            'visibility' => fake()->randomElement(['public', 'unlisted', 'private']),
            'is_active' => true,
        ];
    }
}
