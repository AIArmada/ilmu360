<?php

declare(strict_types=1);

namespace Database\Seeders\AIArmada;

use AIArmada\Events\Models\EventClassification;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Events\Models\EventTermPolicy;
use App\Services\EventCategoryCatalog;
use App\Support\Cache\SelectionCatalogCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventTaxonomySeeder extends Seeder
{
    /** @var array<string, array{label: string, policies?: list<string>}> */
    private const array CATEGORIES = [
        'kuliah_ceramah' => ['label' => 'Kuliah / Ceramah', 'policies' => ['requires_speaker']],
        'kelas_kursus' => ['label' => 'Kelas / Kursus', 'policies' => ['requires_speaker']],
        'bengkel_latihan' => ['label' => 'Bengkel / Latihan', 'policies' => ['requires_speaker']],
        'seminar_persidangan' => ['label' => 'Seminar / Persidangan', 'policies' => ['requires_speaker']],
        'forum_diskusi' => ['label' => 'Forum / Diskusi', 'policies' => ['requires_speaker']],
        'aktiviti_keagamaan' => ['label' => 'Aktiviti Keagamaan'],
        'komuniti_kebajikan' => ['label' => 'Komuniti / Kebajikan', 'policies' => ['requires_physical_delivery']],
        'lain_lain' => ['label' => 'Lain-lain'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $taxonomy = EventTaxonomy::query()->updateOrCreate(
                ['code' => EventCategoryCatalog::TAXONOMY_CODE],
                [
                    'name' => 'Event Category',
                    'description' => 'Activity types for events.',
                    'is_hierarchical' => false,
                    'is_active' => true,
                ],
            );

            $categoryCodes = array_keys(self::CATEGORIES);
            $existingTerms = EventTerm::query()
                ->where('event_taxonomy_id', $taxonomy->id)
                ->get(['id', 'code']);
            $staleTermIds = $existingTerms
                ->reject(fn (EventTerm $term): bool => in_array($term->code, $categoryCodes, true))
                ->pluck('id');

            if ($staleTermIds->isNotEmpty()) {
                EventClassification::query()->whereIn('event_term_id', $staleTermIds)->delete();
                EventTermPolicy::query()->whereIn('event_term_id', $staleTermIds)->delete();
                EventTerm::query()->whereIn('id', $staleTermIds)->delete();
            }

            $currentTermIds = $existingTerms
                ->filter(fn (EventTerm $term): bool => in_array($term->code, $categoryCodes, true))
                ->pluck('id');
            EventTermPolicy::query()->whereIn('event_term_id', $currentTermIds)->delete();

            $policies = [];
            $termOrder = 0;

            foreach (self::CATEGORIES as $code => $definition) {
                $term = EventTerm::query()->updateOrCreate(
                    ['event_taxonomy_id' => $taxonomy->id, 'code' => $code],
                    [
                        'parent_id' => null,
                        'name' => $definition['label'],
                        'sort_order' => $termOrder++,
                        'is_active' => true,
                    ],
                );

                foreach ($definition['policies'] ?? [] as $policyCode) {
                    $policies[] = [
                        'id' => (string) Str::uuid(),
                        'event_term_id' => (string) $term->getKey(),
                        'policy_code' => $policyCode,
                        'is_enabled' => true,
                    ];
                }
            }

            EventTermPolicy::query()->upsert($policies, ['event_term_id', 'policy_code'], ['is_enabled']);
        });

        app(SelectionCatalogCache::class)->bustEventCategories();

    }
}
