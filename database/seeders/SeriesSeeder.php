<?php

namespace Database\Seeders;

use App\Models\Series;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nnjeim\World\Models\Language;

class SeriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Series::query()->exists()) {
            return;
        }

        Series::unsetEventDispatcher();

        try {
            DB::transaction(function (): void {
                // Pre-load language IDs
                $languageIds = Language::query()->pluck('id')->toArray();

                // Create a batch of series
                $seriesToCreate = [];
                $count = 10;

                for ($i = 0; $i < $count; $i++) {
                    $seriesToCreate[] = Series::factory()->make([
                        'visibility' => 'public',
                    ])->toArray();
                }

                // Insert series in chunks
                foreach (array_chunk($seriesToCreate, 100) as $chunk) {
                    Series::insert(array_map(fn ($s) => array_merge($s, [
                        'id' => (string) Str::uuid(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]), $chunk));
                }

                // Get created series IDs and attach languages
                $seriesIds = Series::query()->pluck('id')->toArray();
                $languageAttachments = [];

                foreach ($seriesIds as $seriesId) {
                    $numLanguages = fake()->numberBetween(1, 2);
                    $selectedLanguages = array_rand(array_flip($languageIds), min($numLanguages, count($languageIds)));
                    $selectedLanguages = is_array($selectedLanguages) ? $selectedLanguages : [$selectedLanguages];

                    foreach ($selectedLanguages as $langId) {
                        $languageAttachments[] = [
                            'languageable_type' => 'series',
                            'languageable_id' => $seriesId,
                            'language_id' => $langId,
                        ];
                    }
                }

                // Bulk insert language attachments
                foreach (array_chunk($languageAttachments, 500) as $chunk) {
                    DB::table('languageables')->insert($chunk);
                }
            });
        } finally {
            Series::setEventDispatcher(app('events'));
        }
    }
}
