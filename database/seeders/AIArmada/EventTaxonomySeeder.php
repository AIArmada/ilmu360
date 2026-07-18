<?php

declare(strict_types=1);

namespace Database\Seeders\AIArmada;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Models\EventTermPolicy;
use App\Services\EventCategoryCatalog;
use Illuminate\Database\Seeder;

final class EventTaxonomySeeder extends Seeder
{
    /** @var array<string, array{label: string, terms: array<string, array{label: string, policies?: list<string>}>}> */
    private const array CATEGORIES = [
        'ilmu' => ['label' => 'Ilmu', 'terms' => [
            'kuliah_ceramah' => ['label' => 'Kuliah / Ceramah', 'policies' => ['requires_speaker']],
            'kelas_daurah' => ['label' => 'Kelas / Daurah', 'policies' => ['requires_speaker']],
            'talim' => ['label' => "Ta'lim", 'policies' => ['requires_speaker']],
            'forum' => ['label' => 'Forum', 'policies' => ['requires_speaker']],
            'seminar_konvensyen' => ['label' => 'Seminar / Konvensyen', 'policies' => ['requires_speaker']],
            'tazkirah' => ['label' => 'Tazkirah', 'policies' => ['requires_speaker']],
            'khutbah_jumaat' => ['label' => 'Khutbah Jumaat'],
        ]],
        'ibadah' => ['label' => 'Ibadah', 'terms' => [
            'qiamullail' => ['label' => 'Qiamullail'],
            'tahlil' => ['label' => 'Tahlil'],
            'solat_hajat' => ['label' => 'Solat Hajat'],
        ]],
        'zikir_doa' => ['label' => 'Zikir & Doa', 'terms' => [
            'zikir' => ['label' => 'Zikir'],
            'selawat' => ['label' => 'Selawat'],
            'doa_selamat' => ['label' => 'Doa Selamat'],
        ]],
        'tilawah_category' => ['label' => 'Tilawah', 'terms' => [
            'bacaan_yasin' => ['label' => 'Bacaan Yasin'],
            'khatam_quran' => ['label' => 'Khatam Al-Quran'],
            'tilawah' => ['label' => 'Tilawah Al-Quran'],
            'hafazan_quran' => ['label' => 'Hafazan Al-Quran'],
        ]],
        'komuniti' => ['label' => 'Komuniti', 'terms' => [
            'gotong_royong' => ['label' => 'Gotong Royong', 'policies' => ['requires_physical_delivery']],
            'kenduri' => ['label' => 'Kenduri', 'policies' => ['requires_physical_delivery']],
            'iftar' => ['label' => 'Iftar / Berbuka Puasa', 'policies' => ['requires_physical_delivery']],
            'sahur' => ['label' => 'Sahur', 'policies' => ['requires_physical_delivery']],
            'korban' => ['label' => 'Korban', 'policies' => ['requires_physical_delivery']],
            'aqiqah' => ['label' => 'Aqiqah', 'policies' => ['requires_physical_delivery']],
        ]],
        'lain_lain' => ['label' => 'Lain-lain', 'terms' => [
            'other' => ['label' => 'Lain-lain'],
        ]],
    ];

    public function run(): void
    {
        $taxonomy = EventTaxonomy::query()->updateOrCreate(
            ['code' => EventCategoryCatalog::TAXONOMY_CODE],
            [
                'name' => 'Event Category',
                'description' => 'Hierarchical categories for events.',
                'is_hierarchical' => true,
                'is_active' => true,
            ],
        );

        $policies = [];

        $rootOrder = 0;

        foreach (self::CATEGORIES as $rootCode => $rootDefinition) {
            $root = EventTerm::query()->updateOrCreate(
                ['event_taxonomy_id' => $taxonomy->id, 'code' => $rootCode],
                [
                    'parent_id' => null,
                    'name' => $rootDefinition['label'],
                    'sort_order' => $rootOrder++,
                    'is_active' => true,
                ],
            );

            $termOrder = 0;
            foreach ($rootDefinition['terms'] as $code => $definition) {
                $term = EventTerm::query()->updateOrCreate(
                    ['event_taxonomy_id' => $taxonomy->id, 'code' => $code],
                    [
                        'parent_id' => $root->id,
                        'name' => $definition['label'],
                        'sort_order' => $termOrder++,
                        'is_active' => true,
                    ],
                );

                foreach ($definition['policies'] ?? [] as $policyCode) {
                    $policies[] = [
                        'event_term_id' => (string) $term->getKey(),
                        'policy_code' => $policyCode,
                        'is_enabled' => true,
                    ];
                }
            }
        }

        EventTermPolicy::query()->upsert($policies, ['event_term_id', 'policy_code'], ['is_enabled']);

    }
}
