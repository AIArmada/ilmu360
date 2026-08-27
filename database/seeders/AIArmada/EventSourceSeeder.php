<?php

declare(strict_types=1);

namespace Database\Seeders\AIArmada;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\EventTaxonomyCode;
use App\Enums\TaxonomyTerm\SourceTermCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class EventSourceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $taxonomy = EventTaxonomy::query()->updateOrCreate(
                ['code' => EventTaxonomyCode::Source->value],
                [
                    'name' => 'Event Sources',
                    'description' => 'Reference sources for events.',
                    'is_hierarchical' => false,
                    'is_active' => true,
                ],
            );

            $sourceCodes = array_map(static fn (SourceTermCode $term): string => $term->value, SourceTermCode::cases());
            $existingTerms = EventTerm::query()
                ->where('event_taxonomy_id', $taxonomy->getKey())
                ->get(['id', 'code']);
            $staleTermIds = $existingTerms
                ->reject(fn (EventTerm $term): bool => in_array($term->code, $sourceCodes, true))
                ->pluck('id');

            if ($staleTermIds->isNotEmpty()) {
                EventTerm::query()->whereIn('id', $staleTermIds)->update(['is_active' => false]);
            }

            $termOrder = 0;

            foreach (SourceTermCode::cases() as $term) {
                EventTerm::query()->updateOrCreate(
                    ['event_taxonomy_id' => $taxonomy->getKey(), 'code' => $term->value],
                    [
                        'parent_id' => null,
                        'name' => $term->label(),
                        'sort_order' => $termOrder++,
                        'is_active' => true,
                    ],
                );
            }

            $taxonomy->touch();
        });
    }
}
