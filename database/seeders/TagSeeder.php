<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TagType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            // DOMAIN (Big 3)
            ['type' => TagType::Domain, 'slug' => 'aqidah', 'name' => 'Akidah (Iman & Tauhid)', 'sort' => 10],
            ['type' => TagType::Domain, 'slug' => 'syariah', 'name' => 'Syariah (Hukum & Amalan)', 'sort' => 20],
            ['type' => TagType::Domain, 'slug' => 'akhlak', 'name' => 'Akhlak / Tasawwuf (Adab & Penyucian Jiwa)', 'sort' => 30],

            // SOURCE
            ['type' => TagType::Source, 'slug' => 'quran', 'name' => 'Al-Qur\'an', 'sort' => 10],
            ['type' => TagType::Source, 'slug' => 'hadith', 'name' => 'Hadis / Sunnah', 'sort' => 20],
            ['type' => TagType::Source, 'slug' => 'athar', 'name' => 'Athar Sahabat', 'sort' => 30],
            ['type' => TagType::Source, 'slug' => 'turath', 'name' => 'Kitab Turath (Karya Ulama Klasik)', 'sort' => 40],
            ['type' => TagType::Source, 'slug' => 'contemporary', 'name' => 'Kajian Semasa (Rujukan Moden)', 'sort' => 50],

            // DISCIPLINE (starter set)
            ['type' => TagType::Discipline, 'slug' => 'tafsir', 'name' => 'Tafsir', 'sort' => 10],
            ['type' => TagType::Discipline, 'slug' => 'tadabbur', 'name' => 'Tadabbur', 'sort' => 20],
            ['type' => TagType::Discipline, 'slug' => 'tajwid', 'name' => 'Tajwid', 'sort' => 30],
            ['type' => TagType::Discipline, 'slug' => 'ulum_al_quran', 'name' => 'Ulum al-Qur\'an', 'sort' => 40],
            ['type' => TagType::Discipline, 'slug' => 'hadith_studies', 'name' => 'Pengajian Hadis', 'sort' => 50],
            ['type' => TagType::Discipline, 'slug' => 'sirah', 'name' => 'Sirah Nabawiyyah', 'sort' => 60],
            ['type' => TagType::Discipline, 'slug' => 'ibadah', 'name' => 'Fiqh Ibadah', 'sort' => 70],
            ['type' => TagType::Discipline, 'slug' => 'muamalat', 'name' => 'Muamalat / Ekonomi Islam', 'sort' => 80],
            ['type' => TagType::Discipline, 'slug' => 'munakahat', 'name' => 'Munakahat / Keluarga', 'sort' => 90],
            ['type' => TagType::Discipline, 'slug' => 'siyasah', 'name' => 'Siyasah / Kepimpinan', 'sort' => 100],
            ['type' => TagType::Discipline, 'slug' => 'tazkiyah', 'name' => 'Tazkiyah al-Nafs', 'sort' => 110],
            ['type' => TagType::Discipline, 'slug' => 'adab_akhlaq', 'name' => 'Adab & Akhlak', 'sort' => 120],

            // ISSUE (starter set)
            ['type' => TagType::Issue, 'slug' => 'rasuah', 'name' => 'Rasuah', 'sort' => 10],
            ['type' => TagType::Issue, 'slug' => 'kepimpinan', 'name' => 'Kepimpinan & Amanah', 'sort' => 20],
            ['type' => TagType::Issue, 'slug' => 'perpaduan', 'name' => 'Perpaduan & Kepelbagaian Bangsa', 'sort' => 30],
            ['type' => TagType::Issue, 'slug' => 'dosa_besar', 'name' => 'Dosa Besar', 'sort' => 40],
            ['type' => TagType::Issue, 'slug' => 'keluarga', 'name' => 'Keluarga & Keibubapaan', 'sort' => 50],
            ['type' => TagType::Issue, 'slug' => 'akhlak_digital', 'name' => 'Akhlak Digital', 'sort' => 60],
        ];

        foreach ($tags as $t) {
            $slugJson = json_encode(['en' => $t['slug'], 'ms' => $t['slug']]);
            $nameJson = json_encode(['en' => $t['name'], 'ms' => $t['name']]);

            $existing = DB::table('tags')
                ->whereRaw("slug->>'en' = ?", [$t['slug']])
                ->first();

            if ($existing) {
                DB::table('tags')
                    ->where('id', $existing->id)
                    ->update([
                        'name' => $nameJson,
                        'slug' => $slugJson,
                        'type' => $t['type']->value,
                        'order_column' => $t['sort'],
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('tags')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => $nameJson,
                    'slug' => $slugJson,
                    'type' => $t['type']->value,
                    'order_column' => $t['sort'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }
    }
}
