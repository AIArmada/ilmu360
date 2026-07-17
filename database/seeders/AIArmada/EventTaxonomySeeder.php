<?php

declare(strict_types=1);

namespace Database\Seeders\AIArmada;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventClassification;
use AIArmada\Events\Models\EventTerm;
use App\Services\EventCategoryCatalog;
use Illuminate\Database\Seeder;

final class EventTaxonomySeeder extends Seeder
{
    /** @var array<string, array{label: string, terms: array<string, array{label: string, metadata?: array<string, bool>}>}> */
    private const array CATEGORIES = [
        'ilmu' => ['label' => 'Ilmu', 'terms' => [
            'kuliah_ceramah' => ['label' => 'Kuliah / Ceramah', 'metadata' => ['requires_speaker' => true]],
            'kelas_daurah' => ['label' => 'Kelas / Daurah', 'metadata' => ['requires_speaker' => true]],
            'talim' => ['label' => "Ta'lim", 'metadata' => ['requires_speaker' => true]],
            'forum' => ['label' => 'Forum', 'metadata' => ['requires_speaker' => true]],
            'seminar_konvensyen' => ['label' => 'Seminar / Konvensyen', 'metadata' => ['requires_speaker' => true]],
            'tazkirah' => ['label' => 'Tazkirah', 'metadata' => ['requires_speaker' => true]],
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
            'gotong_royong' => ['label' => 'Gotong Royong', 'metadata' => ['requires_physical_delivery' => true]],
            'kenduri' => ['label' => 'Kenduri', 'metadata' => ['requires_physical_delivery' => true]],
            'iftar' => ['label' => 'Iftar / Berbuka Puasa', 'metadata' => ['requires_physical_delivery' => true]],
            'sahur' => ['label' => 'Sahur', 'metadata' => ['requires_physical_delivery' => true]],
            'korban' => ['label' => 'Korban', 'metadata' => ['requires_physical_delivery' => true]],
            'aqiqah' => ['label' => 'Aqiqah', 'metadata' => ['requires_physical_delivery' => true]],
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

        $rootOrder = 0;

        foreach (self::CATEGORIES as $rootCode => $rootDefinition) {
            $root = EventTerm::query()->updateOrCreate(
                ['event_taxonomy_id' => $taxonomy->id, 'code' => $rootCode],
                [
                    'parent_id' => null,
                    'name' => $rootDefinition['label'],
                    'sort_order' => $rootOrder++,
                    'is_active' => true,
                    'metadata' => ['selectable' => true],
                ],
            );

            $termOrder = 0;
            foreach ($rootDefinition['terms'] as $code => $definition) {
                EventTerm::query()->updateOrCreate(
                    ['event_taxonomy_id' => $taxonomy->id, 'code' => $code],
                    [
                        'parent_id' => $root->id,
                        'name' => $definition['label'],
                        'sort_order' => $termOrder++,
                        'is_active' => true,
                        'metadata' => ['selectable' => true, ...($definition['metadata'] ?? [])],
                    ],
                );
            }
        }

        $legacyTaxonomyIds = EventTaxonomy::query()
            ->where('code', 'event_type')
            ->pluck('id');

        if ($legacyTaxonomyIds->isNotEmpty()) {
            EventClassification::query()
                ->whereIn('event_taxonomy_id', $legacyTaxonomyIds)
                ->delete();

            EventTerm::query()
                ->whereIn('event_taxonomy_id', $legacyTaxonomyIds)
                ->delete();

            EventTaxonomy::query()
                ->whereIn('id', $legacyTaxonomyIds)
                ->delete();
        }

        // Old EventType terms can outlive their taxonomy because the package
        // intentionally does not add database foreign-key cascades.
        $legacyTermIds = EventTerm::query()
            ->whereNotNull('metadata->group')
            ->pluck('id');

        if ($legacyTermIds->isNotEmpty()) {
            EventClassification::query()
                ->whereIn('event_term_id', $legacyTermIds)
                ->delete();

            EventTerm::query()
                ->whereIn('id', $legacyTermIds)
                ->delete();
        }
    }
}
