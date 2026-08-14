<?php

declare(strict_types=1);

namespace Database\Seeders\AIArmada;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\EventTaxonomyCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class EventTopicSeeder extends Seeder
{
    /** @var array<string, string> */
    private const array TOPICS = [
        'agama_kerohanian' => 'Agama & Kerohanian',
        'pendidikan' => 'Pendidikan',
        'sains_matematik' => 'Sains & Matematik',
        'teknologi_it' => 'Teknologi & IT',
        'kerjaya_kemahiran' => 'Kerjaya & Kemahiran',
        'kesihatan' => 'Kesihatan',
        'keluarga_masyarakat' => 'Keluarga & Masyarakat',
        'lain_lain' => 'Lain-lain / Tulis sendiri',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $taxonomy = EventTaxonomy::query()->updateOrCreate(
                ['code' => EventTaxonomyCode::Domain->value],
                [
                    'name' => 'Event Topics',
                    'description' => 'Broad optional topics for events.',
                    'is_hierarchical' => false,
                    'is_active' => true,
                ],
            );

            $topicCodes = array_keys(self::TOPICS);
            $existingTerms = EventTerm::query()
                ->where('event_taxonomy_id', $taxonomy->getKey())
                ->get(['id', 'code']);
            $staleTermIds = $existingTerms
                ->reject(fn (EventTerm $term): bool => in_array($term->code, $topicCodes, true))
                ->pluck('id');

            if ($staleTermIds->isNotEmpty()) {
                EventTerm::query()->whereIn('id', $staleTermIds)->update(['is_active' => false]);
            }

            $termOrder = 0;

            foreach (self::TOPICS as $code => $name) {
                EventTerm::query()->updateOrCreate(
                    ['event_taxonomy_id' => $taxonomy->getKey(), 'code' => $code],
                    [
                        'parent_id' => null,
                        'name' => $name,
                        'sort_order' => $termOrder++,
                        'is_active' => true,
                    ],
                );
            }

            $taxonomy->touch();
        });
    }
}
